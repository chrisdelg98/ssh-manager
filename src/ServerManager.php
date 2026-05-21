<?php
declare(strict_types=1);

namespace App;

use PDO;

class ServerManager
{
    private PDO    $db;
    private string $encKey;

    public function __construct(PDO $db, string $encKey)
    {
        $this->db     = $db;
        $this->encKey = $encKey;
    }

    /**
     * List all servers (without decrypting credentials).
     */
    public function listAll(): array
    {
        return $this->db->query(
            'SELECT id, name, type, host, port, auth_type, color_tag, active, created_at
             FROM servers ORDER BY type, name'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get a single server with decrypted credentials.
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM servers WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->decrypt($row);
    }

    /**
     * Add a new server. Returns new ID.
     */
    public function create(
        string  $name,
        string  $type,
        string  $host,
        int     $port,
        string  $sshUser,
        string  $authType,
        string  $credential,
        ?string $notes    = null,
        string  $colorTag = '#4A90D9'
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO servers
             (name, type, host, port, ssh_user_enc, auth_type, credential_enc, notes_enc, color_tag)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $name,
            $type,
            $host,
            $port,
            Encryption::encrypt($sshUser, $this->encKey),
            $authType,
            Encryption::encrypt($credential, $this->encKey),
            $notes ? Encryption::encrypt($notes, $this->encKey) : null,
            $colorTag,
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Update server. Only re-encrypts fields that are provided (non-null).
     */
    public function update(
        int     $id,
        string  $name,
        string  $type,
        string  $host,
        int     $port,
        ?string $sshUser    = null,
        string  $authType   = 'password',
        ?string $credential = null,
        ?string $notes      = null,
        string  $colorTag   = '#4A90D9'
    ): void {
        $current = $this->get($id);
        if (!$current) {
            throw new \RuntimeException('Server not found');
        }

        $encUser  = Encryption::encrypt($sshUser ?? $current['ssh_user'], $this->encKey);
        $encCred  = $credential !== null
            ? Encryption::encrypt($credential, $this->encKey)
            : Encryption::encrypt($current['credential'], $this->encKey);
        $encNotes = ($notes !== null)
            ? Encryption::encrypt($notes, $this->encKey)
            : ($current['notes'] ? Encryption::encrypt($current['notes'], $this->encKey) : null);

        $stmt = $this->db->prepare(
            'UPDATE servers SET name=?, type=?, host=?, port=?, ssh_user_enc=?,
             auth_type=?, credential_enc=?, notes_enc=?, color_tag=?, updated_at=NOW()
             WHERE id=?'
        );
        $stmt->execute([$name, $type, $host, $port, $encUser, $authType, $encCred, $encNotes, $colorTag, $id]);
    }

    /**
     * Soft-delete (set active = 0).
     */
    public function deactivate(int $id): void
    {
        $this->db->prepare('UPDATE servers SET active=0 WHERE id=?')->execute([$id]);
    }

    /**
     * Hard delete.
     */
    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM servers WHERE id=?')->execute([$id]);
    }

    /**
     * Store verified host key (encrypted) after first successful connection.
     */
    public function saveHostKey(int $id, string $hostKey): void
    {
        $this->db->prepare('UPDATE servers SET hostkey_enc=? WHERE id=?')
                 ->execute([Encryption::encrypt($hostKey, $this->encKey), $id]);
    }

    /**
     * Get stored host key for a server.
     */
    public function getHostKey(int $id): ?string
    {
        $stmt = $this->db->prepare('SELECT hostkey_enc FROM servers WHERE id=?');
        $stmt->execute([$id]);
        $enc = $stmt->fetchColumn();
        if (!$enc) {
            return null;
        }
        return Encryption::decrypt($enc, $this->encKey);
    }

    private function decrypt(array $row): array
    {
        $row['ssh_user']   = Encryption::decrypt($row['ssh_user_enc'], $this->encKey);
        $row['credential'] = Encryption::decrypt($row['credential_enc'], $this->encKey);
        $row['notes']      = $row['notes_enc'] ? Encryption::decrypt($row['notes_enc'], $this->encKey) : null;
        // Remove raw encrypted blobs from the returned array
        unset($row['ssh_user_enc'], $row['credential_enc'], $row['notes_enc'], $row['hostkey_enc']);
        return $row;
    }
}
