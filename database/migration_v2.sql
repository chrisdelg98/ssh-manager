-- =====================================================================
-- SSH Manager — Migration v2
-- Apply this to an existing DB created with the original schema.
-- Idempotent: safe to run multiple times.
-- =====================================================================

SET NAMES utf8mb4;

-- 1) Add theme preference column to users
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `theme` VARCHAR(20) NOT NULL DEFAULT 'matrix' AFTER `master_salt`;

-- 2) Add ssh_key_id link on servers (FK is loose: no CASCADE so deleting
--    a key never silently breaks a server entry).
ALTER TABLE `servers`
    ADD COLUMN IF NOT EXISTS `ssh_key_id` INT UNSIGNED NULL AFTER `credential_enc`,
    ADD KEY IF NOT EXISTS `idx_ssh_key` (`ssh_key_id`);

-- 3) Create ssh_keys table
CREATE TABLE IF NOT EXISTS `ssh_keys` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`            VARCHAR(100) NOT NULL,
  `key_type`        ENUM('rsa','ed25519','ecdsa','dsa','other') NOT NULL DEFAULT 'rsa',
  `bits`            SMALLINT UNSIGNED NULL,
  `fingerprint`     VARCHAR(128) NOT NULL,
  `public_key_enc`  MEDIUMTEXT   NULL,
  `private_key_enc` MEDIUMTEXT   NOT NULL,
  `passphrase_enc`  TEXT         NULL,
  `notes_enc`       TEXT         NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fingerprint` (`fingerprint`),
  KEY `idx_name`        (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
