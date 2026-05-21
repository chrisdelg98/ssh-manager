<?php
/**
 * SSH Manager — One-shot tag migration (v3).
 *
 * Run this ONCE after applying database/migration_v3.sql.
 * Splits the comma-separated `command_library.tags` column into the new
 * `snippet_tags` + `snippet_tag_links` relational structure.
 *
 * Idempotent: re-running detects already-linked snippets and skips them.
 *
 * SECURITY: delete this file after a successful run.
 */
declare(strict_types=1);

define('APP_ROOT', __DIR__);
require_once __DIR__ . '/vendor/autoload.php';

use App\Env;
use App\Database;

// Block accidental re-deploy by requiring a hardcoded query-string token.
$token = $_GET['go'] ?? '';
if ($token !== 'yes') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><meta charset="utf-8">';
    echo '<title>Migrate</title>';
    echo '<style>body{font-family:system-ui;background:#0a0e0a;color:#c8e6c9;padding:2rem;max-width:680px;margin:auto}';
    echo 'h1{color:#00ff88}code{background:#111811;padding:.2rem .45rem;border-radius:4px}a{color:#00ff88}</style>';
    echo '<h1>Tag migration</h1>';
    echo '<p>Este script migra las tags de los snippets de la columna <code>tags</code> a las tablas relacionales nuevas.</p>';
    echo '<p>Antes de continuar verifica que:</p><ul>';
    echo '<li>Subiste el código nuevo</li>';
    echo '<li>Corriste <code>database/migration_v3.sql</code> en phpMyAdmin</li>';
    echo '</ul>';
    echo '<p>Cuando estés listo: <a href="?go=yes"><strong>ejecutar migración →</strong></a></p>';
    echo '<p style="margin-top:2rem;color:#6e8c70;font-size:.85rem">⚠ Borra este archivo después de correrlo.</p>';
    exit;
}

Env::load(APP_ROOT . '/.env');
$config = require __DIR__ . '/config/config.php';
$db     = Database::connect($config['db']);

header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><meta charset="utf-8">';
echo '<title>Migrating…</title>';
echo '<style>body{font-family:JetBrains Mono,monospace;background:#0a0e0a;color:#c8e6c9;padding:2rem;max-width:680px;margin:auto;line-height:1.6}';
echo 'h1{color:#00ff88}.ok{color:#4ade80}.err{color:#ff4757}</style>';
echo '<h1>Tag migration</h1><pre>';

try {
    // 1) Sanity check: tables exist
    $tables = ['snippet_tags', 'snippet_tag_links', 'command_library'];
    foreach ($tables as $t) {
        $exists = $db->query("SHOW TABLES LIKE '{$t}'")->fetchColumn();
        if (!$exists) {
            throw new RuntimeException("Tabla `{$t}` no existe. Corre migration_v3.sql primero.");
        }
    }
    echo "✓ Tablas presentes\n";

    // 2) Recolectar todas las filas con tags
    $rows = $db->query(
        'SELECT id, tags FROM command_library WHERE tags IS NOT NULL AND tags <> ""'
    )->fetchAll(PDO::FETCH_ASSOC);

    echo "✓ Snippets con tags detectados: " . count($rows) . "\n\n";
    if (empty($rows)) {
        echo "<span class='ok'>Nada que migrar — la app está limpia.</span>\n";
        echo "</pre>";
        exit;
    }

    $tagCache = [];      // name => id
    $insertedTags = 0;
    $insertedLinks = 0;
    $skippedLinks  = 0;

    $insTag  = $db->prepare('INSERT IGNORE INTO snippet_tags (name) VALUES (?)');
    $selTag  = $db->prepare('SELECT id FROM snippet_tags WHERE name = ? LIMIT 1');
    $insLink = $db->prepare('INSERT IGNORE INTO snippet_tag_links (snippet_id, tag_id) VALUES (?, ?)');

    $db->beginTransaction();

    foreach ($rows as $row) {
        $snipId = (int)$row['id'];
        $parts  = explode(',', $row['tags']);
        foreach ($parts as $raw) {
            $name = strtolower(trim($raw));
            if ($name === '') continue;
            if (mb_strlen($name) > 50) $name = mb_substr($name, 0, 50);

            // Obtener o crear el tag
            if (!isset($tagCache[$name])) {
                $insTag->execute([$name]);
                $selTag->execute([$name]);
                $tid = (int)$selTag->fetchColumn();
                if ($tid === 0) {
                    throw new RuntimeException("No se pudo obtener id para tag '$name'");
                }
                $tagCache[$name] = $tid;
                if ($db->lastInsertId() !== '0') $insertedTags++;
            }
            $tagId = $tagCache[$name];

            // Linkear
            $insLink->execute([$snipId, $tagId]);
            if ($insLink->rowCount() > 0) $insertedLinks++;
            else                          $skippedLinks++;
        }
    }

    $db->commit();

    echo "<span class='ok'>✓ Tags únicos creados: $insertedTags</span>\n";
    echo "<span class='ok'>✓ Links snippet→tag creados: $insertedLinks</span>\n";
    if ($skippedLinks > 0) {
        echo "  (saltados $skippedLinks links ya existentes)\n";
    }
    echo "\n<span class='ok'>✅ Migración completada.</span>\n\n";
    echo "<strong style='color:#ff4757'>⚠ BORRA ESTE ARCHIVO AHORA: /migrate.php</strong>\n";

} catch (\Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo "<span class='err'>✗ ERROR: " . htmlspecialchars($e->getMessage()) . "</span>\n";
    error_log('[migrate.php] ' . $e->getMessage());
}

echo '</pre>';
