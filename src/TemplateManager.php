<?php
declare(strict_types=1);

namespace App;

use PDO;

class TemplateManager
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function getAll(): array
    {
        $rows = $this->db->query(
            'SELECT * FROM templates ORDER BY os_target, name'
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['steps'] = json_decode($row['steps'], true);
        }
        unset($row);

        return $rows;
    }

    public function get(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM templates WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['steps'] = json_decode($row['steps'], true);
        return $row;
    }

    /**
     * Create a template.
     * $steps: array of ['command' => string, 'description' => string, 'stop_on_error' => bool]
     */
    public function create(string $name, ?string $description, ?string $osTarget, array $steps): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO templates (name, description, os_target, steps) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$name, $description, $osTarget, json_encode($steps)]);
        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, string $name, ?string $description, ?string $osTarget, array $steps): void
    {
        $stmt = $this->db->prepare(
            'UPDATE templates SET name=?, description=?, os_target=?, steps=? WHERE id=?'
        );
        $stmt->execute([$name, $description, $osTarget, json_encode($steps), $id]);
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM templates WHERE id=?')->execute([$id]);
    }
}
