<?php
declare(strict_types=1);

namespace App;

use PDO;

class CommandLibrary
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Search commands by keyword, category, and/or OS target.
     */
    public function search(string $keyword = '', string $category = '', string $osTarget = ''): array
    {
        $where  = ['1=1'];
        $params = [];

        if ($keyword !== '') {
            $where[]  = '(title LIKE ? OR command LIKE ? OR tags LIKE ? OR description LIKE ?)';
            $term     = '%' . $keyword . '%';
            $params   = array_merge($params, [$term, $term, $term, $term]);
        }
        if ($category !== '') {
            $where[]  = 'category = ?';
            $params[] = $category;
        }
        if ($osTarget !== '') {
            $where[]  = '(os_target = ? OR os_target IS NULL OR os_target = "General")';
            $params[] = $osTarget;
        }

        $whereSQL = implode(' AND ', $where);
        $stmt = $this->db->prepare(
            "SELECT * FROM command_library WHERE $whereSQL ORDER BY usage_count DESC, title ASC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAll(): array
    {
        return $this->db->query(
            'SELECT * FROM command_library ORDER BY category, title'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM command_library WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function create(
        string  $title,
        string  $command,
        string  $category,
        ?string $osTarget    = null,
        ?string $description = null,
        ?string $tags        = null
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO command_library (title, command, category, os_target, description, tags)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$title, $command, $category, $osTarget, $description, $tags]);
        return (int)$this->db->lastInsertId();
    }

    public function update(
        int     $id,
        string  $title,
        string  $command,
        string  $category,
        ?string $osTarget    = null,
        ?string $description = null,
        ?string $tags        = null
    ): void {
        $stmt = $this->db->prepare(
            'UPDATE command_library SET title=?, command=?, category=?, os_target=?, description=?, tags=? WHERE id=?'
        );
        $stmt->execute([$title, $command, $category, $osTarget, $description, $tags, $id]);
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM command_library WHERE id=?')->execute([$id]);
    }

    public function incrementUsage(int $id): void
    {
        $this->db->prepare('UPDATE command_library SET usage_count = usage_count + 1 WHERE id=?')
                 ->execute([$id]);
    }

    public function getCategories(): array
    {
        return $this->db->query(
            'SELECT DISTINCT category FROM command_library ORDER BY category'
        )->fetchAll(PDO::FETCH_COLUMN);
    }
}
