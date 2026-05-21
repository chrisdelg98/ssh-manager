-- =====================================================================
-- SSH Manager — Migration v3
-- Normaliza categorías, OS y tags de snippets en tablas relacionales.
-- Idempotente: safe to run multiple times.
--
-- Importante: ejecuta también `migrate.php` desde el browser DESPUÉS
-- de este SQL para migrar las tags existentes (string -> junction table).
-- =====================================================================

SET NAMES utf8mb4;

-- 1) Lookup tables
CREATE TABLE IF NOT EXISTS `snippet_categories` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(50)   NOT NULL,
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cat_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snippet_os_targets` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(50)   NOT NULL,
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_os_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snippet_tags` (
  `id`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50)  NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tag_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snippet_tag_links` (
  `snippet_id` INT UNSIGNED NOT NULL,
  `tag_id`     INT UNSIGNED NOT NULL,
  PRIMARY KEY (`snippet_id`, `tag_id`),
  KEY `idx_tag` (`tag_id`),
  CONSTRAINT `fk_stl_snip` FOREIGN KEY (`snippet_id`) REFERENCES `command_library` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_stl_tag`  FOREIGN KEY (`tag_id`)     REFERENCES `snippet_tags`    (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) FK columns en command_library (idempotente)
ALTER TABLE `command_library`
  ADD COLUMN IF NOT EXISTS `category_id`  INT UNSIGNED NULL AFTER `category`,
  ADD COLUMN IF NOT EXISTS `os_target_id` INT UNSIGNED NULL AFTER `os_target`,
  ADD KEY IF NOT EXISTS `idx_cl_cat` (`category_id`),
  ADD KEY IF NOT EXISTS `idx_cl_os`  (`os_target_id`);

-- 3) Seeds: categorías por defecto
INSERT IGNORE INTO `snippet_categories` (`name`, `sort_order`) VALUES
  ('General',         10),
  ('Servicios',       20),
  ('Actualizaciones', 30),
  ('Disco',           40),
  ('Red',             50),
  ('Logs',            60),
  ('Procesos',        70),
  ('Transferencia',   80),
  ('Seguridad',       90),
  ('Base de Datos',  100),
  ('Backups',        110),
  ('Performance',    120),
  ('Monitoreo',      130);

-- 4) Seeds: OS / Plataformas por defecto
INSERT IGNORE INTO `snippet_os_targets` (`name`, `sort_order`) VALUES
  ('General',      10),
  ('AlmaLinux',    20),
  ('Ubuntu',       30),
  ('Debian',       40),
  ('CentOS',       50),
  ('Rocky Linux',  60),
  ('Fedora',       70),
  ('cPanel',       80),
  ('Plesk',        90),
  ('FreeBSD',     100),
  ('macOS',       110),
  ('Windows',     120),
  ('Docker',      130);

-- 5) Migrar categorías existentes (las que no estén ya en seeds)
INSERT IGNORE INTO `snippet_categories` (`name`, `sort_order`)
SELECT DISTINCT TRIM(category), 200
FROM `command_library`
WHERE category IS NOT NULL AND TRIM(category) <> '';

-- 6) Migrar OS existentes
INSERT IGNORE INTO `snippet_os_targets` (`name`, `sort_order`)
SELECT DISTINCT TRIM(os_target), 200
FROM `command_library`
WHERE os_target IS NOT NULL AND TRIM(os_target) <> '';

-- 7) Backfill category_id desde el texto
UPDATE `command_library` cl
JOIN  `snippet_categories` sc ON sc.name = TRIM(cl.category)
SET   cl.category_id = sc.id
WHERE cl.category_id IS NULL;

-- 8) Backfill os_target_id desde el texto
UPDATE `command_library` cl
JOIN  `snippet_os_targets` so ON so.name = TRIM(cl.os_target)
SET   cl.os_target_id = so.id
WHERE cl.os_target_id IS NULL;
