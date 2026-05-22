<?php
declare(strict_types=1);

namespace App;

use PDO;

/**
 * Snippet library backed by `command_library` plus relational lookup tables
 * for categories, OS targets and tags.
 *
 * The text columns (`category`, `os_target`, `tags`) still exist for
 * backward compatibility but are NOT the source of truth — IDs are.
 * They are kept in sync on writes as a denormalized convenience.
 */
class CommandLibrary
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ── Snippet queries ─────────────────────────────────────────────────

    /**
     * Search snippets joining lookup tables for display.
     */
    public function search(string $keyword = '', string $category = '', string $osTarget = '', string $tag = ''): array
    {
        $where  = ['1=1'];
        $params = [];

        if ($keyword !== '') {
            $where[]  = '(cl.title LIKE ? OR cl.command LIKE ? OR cl.description LIKE ?
                          OR EXISTS (SELECT 1 FROM snippet_tag_links stl2
                                     JOIN snippet_tags st2 ON st2.id = stl2.tag_id
                                     WHERE stl2.snippet_id = cl.id AND st2.name LIKE ?))';
            $term     = '%' . $keyword . '%';
            $params   = array_merge($params, [$term, $term, $term, $term]);
        }
        if ($category !== '') {
            $where[]  = 'sc.name = ?';
            $params[] = $category;
        }
        if ($osTarget !== '') {
            $where[]  = '(sot.name = ? OR sot.name IS NULL OR sot.name = "General")';
            $params[] = $osTarget;
        }
        if ($tag !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM snippet_tag_links stl3
                                JOIN snippet_tags st3 ON st3.id = stl3.tag_id
                                WHERE stl3.snippet_id = cl.id AND st3.name = ?)';
            $params[] = strtolower(trim($tag));
        }

        $whereSQL = implode(' AND ', $where);
        $stmt = $this->db->prepare(
            "SELECT cl.id, cl.title, cl.command, cl.description, cl.usage_count, cl.created_at,
                    cl.category_id, cl.os_target_id,
                    COALESCE(sc.name, cl.category)  AS category,
                    COALESCE(sot.name, cl.os_target) AS os_target,
                    (SELECT GROUP_CONCAT(st.name ORDER BY st.name SEPARATOR ', ')
                     FROM snippet_tag_links stl
                     JOIN snippet_tags st ON st.id = stl.tag_id
                     WHERE stl.snippet_id = cl.id) AS tags
             FROM command_library cl
             LEFT JOIN snippet_categories sc  ON sc.id  = cl.category_id
             LEFT JOIN snippet_os_targets sot ON sot.id = cl.os_target_id
             WHERE $whereSQL
             ORDER BY cl.usage_count DESC, cl.title ASC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAll(): array
    {
        return $this->search('', '', '');
    }

    public function get(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT cl.id, cl.title, cl.command, cl.description, cl.usage_count, cl.created_at,
                    cl.category_id, cl.os_target_id,
                    COALESCE(sc.name, cl.category)  AS category,
                    COALESCE(sot.name, cl.os_target) AS os_target,
                    (SELECT GROUP_CONCAT(st.name ORDER BY st.name SEPARATOR ', ')
                     FROM snippet_tag_links stl
                     JOIN snippet_tags st ON st.id = stl.tag_id
                     WHERE stl.snippet_id = cl.id) AS tags
             FROM command_library cl
             LEFT JOIN snippet_categories sc  ON sc.id  = cl.category_id
             LEFT JOIN snippet_os_targets sot ON sot.id = cl.os_target_id
             WHERE cl.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        // Also fetch tag IDs for the form picker
        $row['tag_ids'] = $this->getTagIdsForSnippet($id);
        return $row;
    }

    // ── Writes ──────────────────────────────────────────────────────────

    /**
     * Create snippet with optional tag-IDs list.
     * @param int[]    $tagIds
     */
    public function create(
        string  $title,
        string  $command,
        ?int    $categoryId,
        ?int    $osTargetId,
        ?string $description = null,
        array   $tagIds = []
    ): int {
        $catName = $categoryId !== null ? $this->getCategoryName($categoryId) : 'General';
        $osName  = $osTargetId !== null ? $this->getOsTargetName($osTargetId) : null;

        $stmt = $this->db->prepare(
            'INSERT INTO command_library
              (title, command, category, category_id, os_target, os_target_id, description)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$title, $command, $catName, $categoryId, $osName, $osTargetId, $description]);
        $id = (int)$this->db->lastInsertId();

        if (!empty($tagIds)) {
            $this->syncTags($id, $tagIds);
        }
        return $id;
    }

    public function update(
        int     $id,
        string  $title,
        string  $command,
        ?int    $categoryId,
        ?int    $osTargetId,
        ?string $description = null,
        array   $tagIds = []
    ): void {
        $catName = $categoryId !== null ? $this->getCategoryName($categoryId) : 'General';
        $osName  = $osTargetId !== null ? $this->getOsTargetName($osTargetId) : null;

        $stmt = $this->db->prepare(
            'UPDATE command_library
             SET title=?, command=?, category=?, category_id=?,
                 os_target=?, os_target_id=?, description=?
             WHERE id=?'
        );
        $stmt->execute([$title, $command, $catName, $categoryId, $osName, $osTargetId, $description, $id]);

        $this->syncTags($id, $tagIds);
    }

    public function delete(int $id): void
    {
        // tag links are cascaded by FK
        $this->db->prepare('DELETE FROM command_library WHERE id=?')->execute([$id]);
    }

    public function incrementUsage(int $id): void
    {
        $this->db->prepare('UPDATE command_library SET usage_count = usage_count + 1 WHERE id=?')
                 ->execute([$id]);
    }

    // ── Tag link management ────────────────────────────────────────────

    /**
     * Replace the full tag set for a snippet.
     * @param int[] $tagIds
     */
    public function syncTags(int $snippetId, array $tagIds): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM snippet_tag_links WHERE snippet_id = ?')
                     ->execute([$snippetId]);

            if (!empty($tagIds)) {
                $ins = $this->db->prepare(
                    'INSERT IGNORE INTO snippet_tag_links (snippet_id, tag_id) VALUES (?, ?)'
                );
                foreach ($tagIds as $tid) {
                    $tid = (int)$tid;
                    if ($tid > 0) $ins->execute([$snippetId, $tid]);
                }
            }

            // Keep denormalized text in sync
            $names = $this->resolveTagNames($tagIds);
            $this->db->prepare('UPDATE command_library SET tags = ? WHERE id = ?')
                     ->execute([$names ? implode(', ', $names) : null, $snippetId]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function getTagIdsForSnippet(int $snippetId): array
    {
        $stmt = $this->db->prepare(
            'SELECT tag_id FROM snippet_tag_links WHERE snippet_id = ?'
        );
        $stmt->execute([$snippetId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    // ── Lookup table helpers ───────────────────────────────────────────

    /** @return array<int,array{id:int,name:string}> */
    public function getCategories(): array
    {
        return $this->db->query(
            'SELECT id, name FROM snippet_categories ORDER BY sort_order, name'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int,array{id:int,name:string}> */
    public function getOsTargets(): array
    {
        return $this->db->query(
            'SELECT id, name FROM snippet_os_targets ORDER BY sort_order, name'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int,array{id:int,name:string}> */
    public function getTags(): array
    {
        return $this->db->query(
            'SELECT id, name FROM snippet_tags ORDER BY name'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Get name by id, '' if not found. */
    public function getCategoryName(int $id): string
    {
        $stmt = $this->db->prepare('SELECT name FROM snippet_categories WHERE id = ?');
        $stmt->execute([$id]);
        return (string)$stmt->fetchColumn();
    }

    public function getOsTargetName(int $id): string
    {
        $stmt = $this->db->prepare('SELECT name FROM snippet_os_targets WHERE id = ?');
        $stmt->execute([$id]);
        return (string)$stmt->fetchColumn();
    }

    /** Ensure a category with this name exists; returns its id. */
    public function ensureCategory(string $name): int
    {
        $name = trim($name);
        if ($name === '') return 0;
        $stmt = $this->db->prepare('INSERT IGNORE INTO snippet_categories (name, sort_order) VALUES (?, 200)');
        $stmt->execute([$name]);
        $sel = $this->db->prepare('SELECT id FROM snippet_categories WHERE name = ? LIMIT 1');
        $sel->execute([$name]);
        return (int)$sel->fetchColumn();
    }

    public function ensureOsTarget(string $name): int
    {
        $name = trim($name);
        if ($name === '') return 0;
        $stmt = $this->db->prepare('INSERT IGNORE INTO snippet_os_targets (name, sort_order) VALUES (?, 200)');
        $stmt->execute([$name]);
        $sel = $this->db->prepare('SELECT id FROM snippet_os_targets WHERE name = ? LIMIT 1');
        $sel->execute([$name]);
        return (int)$sel->fetchColumn();
    }

    /** Ensure a tag exists and return its id. Names lowercased + trimmed. */
    public function ensureTag(string $name): int
    {
        $name = strtolower(trim($name));
        if ($name === '') return 0;
        if (mb_strlen($name) > 50) $name = mb_substr($name, 0, 50);
        $this->db->prepare('INSERT IGNORE INTO snippet_tags (name) VALUES (?)')->execute([$name]);
        $sel = $this->db->prepare('SELECT id FROM snippet_tags WHERE name = ? LIMIT 1');
        $sel->execute([$name]);
        return (int)$sel->fetchColumn();
    }

    /** @param int[] $ids @return string[] */
    private function resolveTagNames(array $ids): array
    {
        $ids = array_unique(array_filter(array_map('intval', $ids)));
        if (empty($ids)) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT name FROM snippet_tags WHERE id IN ($placeholders) ORDER BY name");
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
