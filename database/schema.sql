-- SSH Manager Database Schema
-- Run this once to initialize the database on a fresh install.

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

CREATE TABLE IF NOT EXISTS `users` (
  `id`              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `username_hash`   VARCHAR(64)      NOT NULL COMMENT 'HMAC-SHA256 of lowercase username for lookup',
  `username_enc`    TEXT             NOT NULL COMMENT 'AES-256-GCM encrypted username for display',
  `password_hash`   VARCHAR(255)     NOT NULL,
  `totp_secret_enc` TEXT             NOT NULL,
  `master_salt`     VARCHAR(64)      NOT NULL,
  `theme`           VARCHAR(20)      NOT NULL DEFAULT 'matrix',
  `failed_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until`    DATETIME         NULL,
  `last_login`      DATETIME         NULL,
  `last_ip`         VARCHAR(45)      NULL,
  `ip_allowlist`    TEXT             NULL,
  `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username_hash` (`username_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `servers` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(100)  NOT NULL,
  `type`           ENUM('VPS','Reseller','Dedicated','Other') NOT NULL DEFAULT 'VPS',
  `host`           VARCHAR(255)  NOT NULL,
  `port`           SMALLINT UNSIGNED NOT NULL DEFAULT 22,
  `ssh_user_enc`   TEXT          NOT NULL,
  `auth_type`      ENUM('password','key') NOT NULL DEFAULT 'password',
  `credential_enc` MEDIUMTEXT    NOT NULL,
  `ssh_key_id`     INT UNSIGNED  NULL COMMENT 'Optional FK to ssh_keys when auth_type=key',
  `hostkey_enc`    TEXT          NULL,
  `notes_enc`      TEXT          NULL,
  `color_tag`      VARCHAR(7)    NOT NULL DEFAULT '#4A90D9',
  `active`         TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ssh_key` (`ssh_key_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ssh_keys` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`            VARCHAR(100) NOT NULL,
  `key_type`        ENUM('rsa','ed25519','ecdsa','dsa','other') NOT NULL DEFAULT 'rsa',
  `bits`            SMALLINT UNSIGNED NULL,
  `fingerprint`     VARCHAR(128) NOT NULL COMMENT 'SHA256 fingerprint (cleartext, identifier only)',
  `public_key_enc`  MEDIUMTEXT   NULL  COMMENT 'AES-256-GCM, optional',
  `private_key_enc` MEDIUMTEXT   NOT NULL COMMENT 'AES-256-GCM, mandatory',
  `passphrase_enc`  TEXT         NULL  COMMENT 'AES-256-GCM, only if key has a passphrase',
  `notes_enc`       TEXT         NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fingerprint` (`fingerprint`),
  KEY `idx_name`        (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `command_library` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `title`       VARCHAR(150)  NOT NULL,
  `command`     TEXT          NOT NULL,
  `category`    VARCHAR(50)   NOT NULL DEFAULT 'General',
  `os_target`   VARCHAR(50)   NULL,
  `description` TEXT          NULL,
  `tags`        VARCHAR(255)  NULL,
  `usage_count` INT UNSIGNED  NOT NULL DEFAULT 0,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FULLTEXT KEY `ft_search` (`title`, `command`, `tags`, `description`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `templates` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(150) NOT NULL,
  `description` TEXT         NULL,
  `os_target`   VARCHAR(50)  NULL,
  `steps`       JSON         NOT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED    NOT NULL,
  `server_id`   INT UNSIGNED    NULL,
  `action_type` VARCHAR(50)     NOT NULL,
  `detail_enc`  MEDIUMTEXT      NULL,
  `ip_address`  VARCHAR(45)     NOT NULL,
  `status`      ENUM('success','failure','error') NOT NULL DEFAULT 'success',
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user`    (`user_id`),
  KEY `idx_server`  (`server_id`),
  KEY `idx_action`  (`action_type`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `fk_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;
