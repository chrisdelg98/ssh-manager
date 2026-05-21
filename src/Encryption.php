<?php
declare(strict_types=1);

namespace App;

class Encryption
{
    private const CIPHER    = 'aes-256-gcm';
    private const TAG_LEN   = 16;
    private const IV_LEN    = 12;
    private const PBKDF2_ALGO = 'sha256';
    private const PBKDF2_ITER = 310000;
    private const KEY_LEN     = 32;

    /**
     * Derive a 256-bit encryption key from master password + salt using PBKDF2.
     * The derived key is NEVER stored in the database — only lives in the PHP session.
     */
    public static function deriveKey(string $masterPassword, string $salt): string
    {
        return hash_pbkdf2(
            self::PBKDF2_ALGO,
            $masterPassword,
            $salt,
            self::PBKDF2_ITER,
            self::KEY_LEN,
            true
        );
    }

    /**
     * Generate a random hex salt for use with deriveKey().
     */
    public static function generateSalt(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Encrypt plaintext with AES-256-GCM.
     * Returns base64-encoded blob: IV (12) + TAG (16) + CIPHERTEXT
     */
    public static function encrypt(string $plaintext, string $key): string
    {
        if (strlen($key) !== self::KEY_LEN) {
            throw new \RuntimeException('Invalid key length');
        }

        $iv  = random_bytes(self::IV_LEN);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LEN
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt a blob produced by encrypt().
     */
    public static function decrypt(string $encoded, string $key): string
    {
        if (strlen($key) !== self::KEY_LEN) {
            throw new \RuntimeException('Invalid key length');
        }

        $blob = base64_decode($encoded, true);
        // AES-GCM allows empty plaintext — minimum valid blob is exactly IV + TAG (28 bytes).
        if ($blob === false || strlen($blob) < self::IV_LEN + self::TAG_LEN) {
            throw new \RuntimeException('Invalid ciphertext');
        }

        $iv         = substr($blob, 0, self::IV_LEN);
        $tag        = substr($blob, self::IV_LEN, self::TAG_LEN);
        $ciphertext = substr($blob, self::IV_LEN + self::TAG_LEN);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed — wrong key or tampered data');
        }

        return $plaintext;
    }

    /**
     * Derive two separate 32-byte keys for username operations from the DB password.
     * Returns ['enc' => key_for_aes, 'lookup' => key_for_hmac].
     * Using the DB password as the root secret means no extra config is needed.
     */
    public static function deriveAppKeys(string $dbPass): array
    {
        return [
            'enc'    => hash_hmac('sha256', 'username-enc-v1',    $dbPass, true), // 32 bytes
            'lookup' => hash_hmac('sha256', 'username-lookup-v1', $dbPass, true), // 32 bytes
        ];
    }

    /**
     * Deterministic HMAC of the username for DB lookup (case-insensitive).
     * Returns a 64-char hex string stored in the username_hash column.
     */
    public static function hashUsername(string $username, string $lookupKey): string
    {
        return hash_hmac('sha256', strtolower(trim($username)), $lookupKey);
    }

    /**
     * Re-encrypt all server credentials after a master password change.
     * $rows: array of ['id', 'ssh_user_enc', 'credential_enc', 'notes_enc', 'hostkey_enc']
     * Returns array of ['id', new_ssh_user_enc, new_credential_enc, new_notes_enc, new_hostkey_enc]
     */
    public static function reEncryptServers(array $rows, string $oldKey, string $newKey): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id'             => $row['id'],
                'ssh_user_enc'   => self::encrypt(self::decrypt($row['ssh_user_enc'], $oldKey), $newKey),
                'credential_enc' => self::encrypt(self::decrypt($row['credential_enc'], $oldKey), $newKey),
                'notes_enc'      => $row['notes_enc']
                                        ? self::encrypt(self::decrypt($row['notes_enc'], $oldKey), $newKey)
                                        : null,
                'hostkey_enc'    => $row['hostkey_enc']
                                        ? self::encrypt(self::decrypt($row['hostkey_enc'], $oldKey), $newKey)
                                        : null,
            ];
        }
        return $result;
    }
}
