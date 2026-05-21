<?php
declare(strict_types=1);

namespace App;

class SessionManager
{
    private int $timeout;

    public function __construct(int $timeoutSeconds = 1800)
    {
        $this->timeout = $timeoutSeconds;
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // ini_set has better compatibility with shared hosting than session_set_cookie_params
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', '1');
        ini_set('session.cookie_samesite', 'Lax'); // Strict can break on some Cloudflare redirect chains
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_path', '/');
        ini_set('session.cookie_lifetime', '0');

        session_name('SSHMGR');
        session_start();
    }

    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    /**
     * Validate session fingerprint and timeout.
     * Returns false if session should be destroyed (invalid or expired).
     */
    public function validate(): bool
    {
        if (empty($_SESSION['user_id'])) {
            return false;
        }

        // Timeout check
        if (isset($_SESSION['last_activity'])) {
            if (time() - $_SESSION['last_activity'] > $this->timeout) {
                $this->destroy();
                return false;
            }
        }

        // Fingerprint check
        $fp = $this->fingerprint();
        if (isset($_SESSION['fingerprint']) && !hash_equals($_SESSION['fingerprint'], $fp)) {
            $this->destroy();
            return false;
        }

        $_SESSION['last_activity'] = time();
        return true;
    }

    public function login(int $userId, string $username, string $encKey): void
    {
        $this->regenerate();
        $_SESSION['user_id']       = $userId;
        $_SESSION['username']      = $username;
        $_SESSION['enc_key']       = base64_encode($encKey); // store as base64 to avoid binary serialisation issues
        $_SESSION['fingerprint']   = $this->fingerprint();
        $_SESSION['last_activity'] = time();
        $_SESSION['auth_step']     = 'complete';
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public function isAuthenticated(): bool
    {
        return $this->validate();
    }

    public function userId(): ?int
    {
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }

    public function username(): ?string
    {
        return $_SESSION['username'] ?? null;
    }

    /**
     * Returns the 32-byte AES key for the current session, or null if absent/corrupt.
     * Tolerates both the current base64-encoded format and any legacy raw-binary value
     * that may still be sitting in old sessions.
     */
    public function encKey(): ?string
    {
        $stored = $_SESSION['enc_key'] ?? null;
        if ($stored === null || $stored === '') {
            return null;
        }

        // Current format: base64 string of 32 binary bytes
        $decoded = base64_decode($stored, true);
        if ($decoded !== false && strlen($decoded) === 32) {
            return $decoded;
        }

        // Legacy format: raw 32 binary bytes stored directly
        if (strlen($stored) === 32) {
            return $stored;
        }

        return null;
    }

    private function fingerprint(): string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return hash('sha256', $ua . '|' . $ip . '|' . session_id());
    }
}
