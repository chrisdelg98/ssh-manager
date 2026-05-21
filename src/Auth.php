<?php
declare(strict_types=1);

namespace App;

use PDO;
use OTPHP\TOTP;

class Auth
{
    private PDO            $db;
    private SessionManager $session;
    private RateLimiter    $limiter;
    private array          $config;
    private array          $appKeys;

    /**
     * $appKeys = Encryption::deriveAppKeys($config['db']['pass'])
     * ['enc' => 32-byte key, 'lookup' => 32-byte key]
     */
    public function __construct(PDO $db, SessionManager $session, RateLimiter $limiter, array $config, array $appKeys)
    {
        $this->db      = $db;
        $this->session = $session;
        $this->limiter = $limiter;
        $this->config  = $config;
        $this->appKeys = $appKeys;
    }

    /**
     * Step 1: Verify username + password.
     * Returns user row on success, null on failure.
     */
    public function verifyPassword(string $username, string $password): ?array
    {
        $usernameHash = Encryption::hashUsername($username, $this->appKeys['lookup']);

        if ($this->limiter->isLocked($usernameHash)) {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT id, username_enc, password_hash, totp_secret_enc, master_salt, theme
             FROM users WHERE username_hash = ? LIMIT 1'
        );
        $stmt->execute([$usernameHash]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            if ($user) {
                $this->limiter->recordFailure($usernameHash);
            }
            return null;
        }

        // Decrypt username for session storage and logging
        $user['username'] = Encryption::decrypt($user['username_enc'], $this->appKeys['enc']);

        $this->limiter->resetFailures($usernameHash);
        return $user;
    }

    /**
     * Step 2: Verify TOTP code.
     * $totpSecretEnc is the encrypted TOTP secret from the DB.
     * We need a temporary key to decrypt it — derived from password in session temp storage.
     */
    public function verifyTotp(string $encryptedSecret, string $code, string $encKey): bool
    {
        try {
            $secret = Encryption::decrypt($encryptedSecret, $encKey);
            $totp   = TOTP::createFromSecret($secret);
            return $totp->verify($code, null, 1); // 1 period tolerance (±30s)
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Step 3: Verify master password and derive encryption key.
     * Returns derived key on success, null on failure.
     */
    public function verifyMasterPassword(string $masterPassword, string $salt): ?string
    {
        // The master password is never stored — we just derive the key and try to
        // decrypt a known field to validate it. Validation happens in ServerManager
        // on first use (or via a test blob stored at registration).
        if (strlen(trim($masterPassword)) < 12) {
            return null;
        }
        return Encryption::deriveKey($masterPassword, $salt);
    }

    /**
     * Complete login after all 3 steps pass.
     */
    public function completeLogin(array $user, string $encKey): void
    {
        $this->session->login((int)$user['id'], $user['username'], $encKey);

        // Carry the user's theme preference into the session
        $_SESSION['theme'] = in_array(($user['theme'] ?? 'matrix'), ['matrix','void','daylight','dusk'], true)
            ? $user['theme']
            : 'matrix';

        $stmt = $this->db->prepare(
            'UPDATE users SET last_login = NOW(), last_ip = ? WHERE id = ?'
        );
        $stmt->execute([$_SERVER['REMOTE_ADDR'] ?? '', $user['id']]);
    }

    /**
     * Update the user's stored theme preference.
     */
    public function updateTheme(int $userId, string $theme): void
    {
        if (!in_array($theme, ['matrix','void','daylight','dusk'], true)) {
            return;
        }
        $stmt = $this->db->prepare('UPDATE users SET theme = ? WHERE id = ?');
        $stmt->execute([$theme, $userId]);
        $_SESSION['theme'] = $theme;
    }

    /**
     * Generate TOTP secret and return provisioning URI for QR code.
     */
    public function generateTotpSecret(string $username, string $appName = 'SSH Manager'): array
    {
        $totp = TOTP::generate();
        $totp->setLabel($username);
        $totp->setIssuer($appName);

        return [
            'secret'       => $totp->getSecret(),
            'provisioning' => $totp->getProvisioningUri(),
        ];
    }

    /**
     * Create a new user. Returns user ID on success.
     */
    public function createUser(
        string $username,
        string $password,
        string $masterPassword,
        string $totpSecret
    ): int {
        $salt         = Encryption::generateSalt();
        $encKey       = Encryption::deriveKey($masterPassword, $salt);
        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 14]);
        $encTotp      = Encryption::encrypt($totpSecret, $encKey);
        $usernameHash = Encryption::hashUsername($username, $this->appKeys['lookup']);
        $usernameEnc  = Encryption::encrypt($username, $this->appKeys['enc']);

        $stmt = $this->db->prepare(
            'INSERT INTO users (username_hash, username_enc, password_hash, totp_secret_enc, master_salt)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$usernameHash, $usernameEnc, $passwordHash, $encTotp, $salt]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Change master password: re-derive key, re-encrypt all server credentials.
     */
    public function changeMasterPassword(
        int $userId,
        string $oldMasterPassword,
        string $newMasterPassword,
        string $salt
    ): bool {
        $oldKey = Encryption::deriveKey($oldMasterPassword, $salt);
        $newKey = Encryption::deriveKey($newMasterPassword, $salt);

        // Re-encrypt all servers
        $rows = $this->db->query(
            'SELECT id, ssh_user_enc, credential_enc, notes_enc, hostkey_enc FROM servers'
        )->fetchAll(PDO::FETCH_ASSOC);

        try {
            $reEncrypted = Encryption::reEncryptServers($rows, $oldKey, $newKey);
        } catch (\Throwable) {
            return false; // Old key was wrong
        }

        // Re-encrypt TOTP secret
        $userRow = $this->db->prepare('SELECT totp_secret_enc FROM users WHERE id = ?');
        $userRow->execute([$userId]);
        $user = $userRow->fetch(PDO::FETCH_ASSOC);

        $newTotpEnc = Encryption::encrypt(Encryption::decrypt($user['totp_secret_enc'], $oldKey), $newKey);

        // Atomic update in transaction
        $this->db->beginTransaction();
        try {
            foreach ($reEncrypted as $srv) {
                $upd = $this->db->prepare(
                    'UPDATE servers SET ssh_user_enc=?, credential_enc=?, notes_enc=?, hostkey_enc=? WHERE id=?'
                );
                $upd->execute([
                    $srv['ssh_user_enc'],
                    $srv['credential_enc'],
                    $srv['notes_enc'],
                    $srv['hostkey_enc'],
                    $srv['id'],
                ]);
            }

            $this->db->prepare('UPDATE users SET totp_secret_enc=? WHERE id=?')
                     ->execute([$newTotpEnc, $userId]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return false;
        }

        $_SESSION['enc_key'] = base64_encode($newKey);
        return true;
    }

    public function logout(): void
    {
        $this->session->destroy();
    }

    /**
     * Get current user's master_salt from DB.
     */
    public function getUserSalt(int $userId): string
    {
        $stmt = $this->db->prepare('SELECT master_salt FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        return $stmt->fetchColumn();
    }
}
