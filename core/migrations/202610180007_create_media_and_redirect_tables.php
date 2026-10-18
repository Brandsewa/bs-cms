<?php

declare(strict_types=1);

namespace BsCms\Migrations;

use BsCms\Database\Migration;

/**
 * Migration 0007: Create media and redirect tables.
 * Adheres strictly to docs/01-data-model.md Sections 5 and 6.
 */
final class CreateMediaAndRedirectTables extends Migration
{
    public function up(): void
    {
        $mediaFolders = $this->connection->prefixTable('media_folders');
        $media = $this->connection->prefixTable('media');
        $mediaVariants = $this->connection->prefixTable('media_variants');
        $mediaUsage = $this->connection->prefixTable('media_usage');
        $redirects = $this->connection->prefixTable('redirects');

        $pdo = $this->connection->getPdo();

        // 1. media_folders
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$mediaFolders}` (
            `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            `parent_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
            `name` VARCHAR(190) NOT NULL,
            `path` VARCHAR(512) NOT NULL,
            `created_at` DATETIME(3) NOT NULL,
            `parent_key` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS (IFNULL(`parent_id`, '')) VIRTUAL,
            UNIQUE KEY `uq_mediafolder` (`parent_key`, `name`),
            KEY `ix_mediafolder_parent` (`parent_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 2. media
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$media}` (
            `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            `folder_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
            `filename` VARCHAR(255) NOT NULL,
            `original_name` VARCHAR(255) NOT NULL,
            `mime` VARCHAR(120) NOT NULL,
            `extension` VARCHAR(16) NOT NULL,
            `size_bytes` BIGINT NOT NULL,
            `width` INT NULL,
            `height` INT NULL,
            `duration_seconds` INT NULL,
            `content_hash` CHAR(64) NOT NULL,
            `storage_driver` VARCHAR(16) NOT NULL DEFAULT 'local',
            `storage_path` VARCHAR(512) NOT NULL,
            `alt_text` VARCHAR(500) NULL,
            `caption` VARCHAR(500) NULL,
            `title` VARCHAR(255) NULL,
            `focal_x` DECIMAL(5,4) NULL,
            `focal_y` DECIMAL(5,4) NULL,
            `blurhash` VARCHAR(64) NULL,
            `dominant_color` VARCHAR(9) NULL,
            `usage_count` INT NOT NULL DEFAULT 0,
            `uploaded_by` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NOT NULL,
            `deleted_at` DATETIME(3) NULL,
            KEY `ix_media_folder` (`folder_id`, `created_at`),
            KEY `ix_media_hash` (`content_hash`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 3. media_variants
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$mediaVariants}` (
            `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            `media_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `preset` VARCHAR(32) NOT NULL,
            `format` VARCHAR(8) NOT NULL,
            `width` INT NOT NULL,
            `height` INT NOT NULL,
            `size_bytes` INT NOT NULL,
            `storage_path` VARCHAR(512) NOT NULL,
            UNIQUE KEY `uq_variant` (`media_id`, `preset`, `format`),
            CONSTRAINT `fk_variant_media` FOREIGN KEY (`media_id`) REFERENCES `{$media}` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 4. media_usage
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$mediaUsage}` (
            `media_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `collection_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `entry_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `field_key` VARCHAR(64) NOT NULL,
            PRIMARY KEY (`media_id`, `collection_id`, `entry_id`, `field_key`),
            KEY `ix_media_usage_entry` (`collection_id`, `entry_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 5. redirects
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$redirects}` (
            `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            `from_path` VARCHAR(512) NOT NULL,
            `match_type` VARCHAR(10) NOT NULL DEFAULT 'exact',
            `to_path` VARCHAR(2048) NOT NULL,
            `status_code` SMALLINT NOT NULL DEFAULT 301,
            `is_auto` TINYINT(1) NOT NULL DEFAULT 0,
            `hits` INT NOT NULL DEFAULT 0,
            `last_hit_at` DATETIME(3) NULL,
            `created_by` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
            `created_at` DATETIME(3) NOT NULL,
            `from_hash` CHAR(64) NOT NULL,
            UNIQUE KEY `uq_redirect_from` (`from_hash`, `match_type`),
            KEY `ix_redirect_from_path` (`from_path`(191))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    public function down(): void
    {
        $mediaFolders = $this->connection->prefixTable('media_folders');
        $media = $this->connection->prefixTable('media');
        $mediaVariants = $this->connection->prefixTable('media_variants');
        $mediaUsage = $this->connection->prefixTable('media_usage');
        $redirects = $this->connection->prefixTable('redirects');

        $pdo = $this->connection->getPdo();

        $pdo->exec("DROP TABLE IF EXISTS `{$redirects}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$mediaUsage}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$mediaVariants}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$media}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$mediaFolders}`;");
    }
}
