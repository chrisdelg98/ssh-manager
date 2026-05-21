<?php
declare(strict_types=1);

namespace App;

use PDO;

class RateLimiter
{
    private PDO $db;
    private int $maxAttempts;
    private int $lockoutMinutes;

    public function __construct(PDO $db, int $maxAttempts = 5, int $lockoutMinutes = 15)
    {
        $this->db             = $db;
        $this->maxAttempts    = $maxAttempts;
        $this->lockoutMinutes = $lockoutMinutes;
    }

    /**
     * Returns true if the account is currently locked.
     */
    public function isLocked(string $usernameHash): bool
    {
        $stmt = $this->db->prepare(
            'SELECT locked_until FROM users WHERE username_hash = ? LIMIT 1'
        );
        $stmt->execute([$usernameHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || $row['locked_until'] === null) {
            return false;
        }

        return strtotime($row['locked_until']) > time();
    }

    /**
     * Record a failed login attempt, locking the account if threshold is reached.
     */
    public function recordFailure(string $usernameHash): void
    {
        $stmt = $this->db->prepare(
            'UPDATE users
             SET failed_attempts = failed_attempts + 1,
                 locked_until = CASE
                     WHEN failed_attempts + 1 >= ? THEN DATE_ADD(NOW(), INTERVAL ? MINUTE)
                     ELSE locked_until
                 END
             WHERE username_hash = ?'
        );
        $stmt->execute([$this->maxAttempts, $this->lockoutMinutes, $usernameHash]);
    }

    /**
     * Reset failed attempts on successful login.
     */
    public function resetFailures(string $usernameHash): void
    {
        $stmt = $this->db->prepare(
            'UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE username_hash = ?'
        );
        $stmt->execute([$usernameHash]);
    }

    /**
     * Returns remaining lockout seconds (0 if not locked).
     */
    public function lockoutRemainingSeconds(string $usernameHash): int
    {
        $stmt = $this->db->prepare(
            'SELECT locked_until FROM users WHERE username_hash = ? LIMIT 1'
        );
        $stmt->execute([$usernameHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || $row['locked_until'] === null) {
            return 0;
        }

        $remaining = strtotime($row['locked_until']) - time();
        return max(0, $remaining);
    }
}
