<?php
declare(strict_types=1);

namespace App;

use PDO;

class Logger
{
    private PDO    $db;
    private string $encKey;
    private array  $appKeys;

    // Action type constants
    public const LOGIN          = 'LOGIN';
    public const LOGOUT         = 'LOGOUT';
    public const LOGIN_FAIL     = 'LOGIN_FAIL';
    public const SSH_EXEC       = 'SSH_EXEC';
    public const TEMPLATE_RUN   = 'TEMPLATE_RUN';
    public const SERVER_ADD     = 'SERVER_ADD';
    public const SERVER_EDIT    = 'SERVER_EDIT';
    public const SERVER_DELETE  = 'SERVER_DELETE';
    public const COMMAND_COPY   = 'COMMAND_COPY';
    public const SETTINGS_CHANGE = 'SETTINGS_CHANGE';
    public const KEY_ADD        = 'KEY_ADD';
    public const KEY_EDIT       = 'KEY_EDIT';
    public const KEY_DELETE     = 'KEY_DELETE';

    public function __construct(PDO $db, string $encKey, array $appKeys = [])
    {
        $this->db      = $db;
        $this->encKey  = $encKey;
        $this->appKeys = $appKeys;
    }

    /**
     * Log an action. Detail is stored encrypted.
     */
    public function log(
        int     $userId,
        string  $actionType,
        string  $status,
        ?int    $serverId = null,
        ?string $detail   = null
    ): void {
        $encDetail = $detail ? Encryption::encrypt($detail, $this->encKey) : null;

        $stmt = $this->db->prepare(
            'INSERT INTO audit_logs (user_id, server_id, action_type, detail_enc, ip_address, status)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $serverId,
            $actionType,
            $encDetail,
            $this->clientIp(),
            $status,
        ]);
    }

    /**
     * Fetch paginated logs (decrypted). Latest first.
     */
    public function getPage(int $page = 1, int $perPage = 50, array $filters = []): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['server_id'])) {
            $where[]  = 'l.server_id = ?';
            $params[] = (int)$filters['server_id'];
        }
        if (!empty($filters['action_type'])) {
            $where[]  = 'l.action_type = ?';
            $params[] = $filters['action_type'];
        }
        if (!empty($filters['status'])) {
            $where[]  = 'l.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['date_from'])) {
            $where[]  = 'l.created_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[]  = 'l.created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        $whereSQL = implode(' AND ', $where);
        $offset   = ($page - 1) * $perPage;

        $stmt = $this->db->prepare(
            "SELECT l.id, l.user_id, u.username_enc, l.server_id, s.name AS server_name,
                    l.action_type, l.detail_enc, l.ip_address, l.status, l.created_at
             FROM audit_logs l
             LEFT JOIN users u   ON u.id = l.user_id
             LEFT JOIN servers s ON s.id = l.server_id
             WHERE $whereSQL
             ORDER BY l.created_at DESC
             LIMIT ? OFFSET ?"
        );
        $params[] = $perPage;
        $params[] = $offset;
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['detail']   = $row['detail_enc']
                ? $this->safeDecrypt($row['detail_enc'])
                : null;
            $row['username'] = $row['username_enc']
                ? $this->safeUsernameDecrypt($row['username_enc'])
                : '—';
            unset($row['detail_enc'], $row['username_enc']);
        }
        unset($row);

        return $rows;
    }

    private function safeUsernameDecrypt(string $enc): string
    {
        if (empty($this->appKeys['enc'])) {
            return '—';
        }
        try {
            return Encryption::decrypt($enc, $this->appKeys['enc']);
        } catch (\Throwable) {
            return '—';
        }
    }

    /**
     * Count total logs matching filters.
     */
    public function count(array $filters = []): int
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['server_id'])) {
            $where[]  = 'server_id = ?';
            $params[] = (int)$filters['server_id'];
        }
        if (!empty($filters['action_type'])) {
            $where[]  = 'action_type = ?';
            $params[] = $filters['action_type'];
        }
        if (!empty($filters['status'])) {
            $where[]  = 'status = ?';
            $params[] = $filters['status'];
        }

        $whereSQL = implode(' AND ', $where);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM audit_logs WHERE $whereSQL");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Purge old logs beyond retention days.
     */
    public function purgeOld(int $retentionDays): int
    {
        $stmt = $this->db->prepare(
            'DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)'
        );
        $stmt->execute([$retentionDays]);
        return $stmt->rowCount();
    }

    private function safeDecrypt(string $enc): string
    {
        try {
            return Encryption::decrypt($enc, $this->encKey);
        } catch (\Throwable) {
            return '[decryption error]';
        }
    }

    private function clientIp(): string
    {
        // Prefer real IP behind proxies (Hostinger may use reverse proxy)
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', $_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }
}
