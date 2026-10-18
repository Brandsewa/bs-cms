<?php

declare(strict_types=1);

namespace BsCms\Migrations;

use BsCms\Database\Migration;

/**
 * Migration 0005: Schema engine and content repository core tables.
 * Strict adherence to docs/01-data-model.md Sections 2, 5, and 7.
 */
final class CreateContentEngineTables extends Migration
{
    public function up(): void
    {
        $collections = $this->connection->prefixTable('collections');
        $fields = $this->connection->prefixTable('fields');
        $schemaChanges = $this->connection->prefixTable('schema_changes');
        $entryVersions = $this->connection->prefixTable('entry_versions');
        $savedViews = $this->connection->prefixTable('saved_views');
        $webhooks = $this->connection->prefixTable('webhooks');
        $webhookDeliveries = $this->connection->prefixTable('webhook_deliveries');
        $entryRelations = $this->connection->prefixTable('entry_relations');

        $pdo = $this->connection->getPdo();

        // 1. collections
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$collections}` (
            `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            `handle` VARCHAR(64) NOT NULL,
            `kind` VARCHAR(12) NOT NULL DEFAULT 'collection',
            `label_singular` VARCHAR(120) NOT NULL,
            `label_plural` VARCHAR(120) NOT NULL,
            `description` VARCHAR(500) NULL,
            `icon` VARCHAR(64) NULL,
            `table_name` VARCHAR(64) NOT NULL,
            `is_system` TINYINT(1) NOT NULL DEFAULT 0,
            `has_public_pages` TINYINT(1) NOT NULL DEFAULT 1,
            `route_pattern` VARCHAR(255) NULL,
            `list_route_pattern` VARCHAR(255) NULL,
            `features` JSON NOT NULL,
            `title_field` VARCHAR(64) NOT NULL DEFAULT 'title',
            `slug_source` VARCHAR(64) NULL DEFAULT 'title',
            `default_sort` VARCHAR(64) NOT NULL DEFAULT '-updated_at',
            `schema_org` JSON NULL,
            `list_columns` JSON NULL,
            `plugin_key` VARCHAR(64) NULL,
            `schema_version` INT NOT NULL DEFAULT 1,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NOT NULL,
            UNIQUE KEY `uq_collections_key` (`handle`),
            UNIQUE KEY `uq_collections_table` (`table_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 2. fields
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$fields}` (
            `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            `collection_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `handle` VARCHAR(64) NOT NULL,
            `label` VARCHAR(120) NOT NULL,
            `type` VARCHAR(32) NOT NULL,
            `config` JSON NOT NULL,
            `required` TINYINT(1) NOT NULL DEFAULT 0,
            `is_unique` TINYINT(1) NOT NULL DEFAULT 0,
            `is_filterable` TINYINT(1) NOT NULL DEFAULT 0,
            `is_sortable` TINYINT(1) NOT NULL DEFAULT 0,
            `is_searchable` TINYINT(1) NOT NULL DEFAULT 0,
            `show_in_list` TINYINT(1) NOT NULL DEFAULT 0,
            `default_value` JSON NULL,
            `help_text` VARCHAR(500) NULL,
            `placeholder` VARCHAR(190) NULL,
            `group_key` VARCHAR(64) NULL,
            `position` INT NOT NULL DEFAULT 0,
            `width` VARCHAR(8) NOT NULL DEFAULT 'full',
            `validation` JSON NULL,
            `conditional` JSON NULL,
            `storage` VARCHAR(16) NOT NULL DEFAULT 'json',
            `column_name` VARCHAR(64) NULL,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NOT NULL,
            UNIQUE KEY `uq_fields_key` (`collection_id`, `handle`),
            CONSTRAINT `fk_fields_collection` FOREIGN KEY (`collection_id`) REFERENCES `{$collections}` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 3. schema_changes
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$schemaChanges}` (
            `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            `collection_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
            `change_type` VARCHAR(32) NOT NULL,
            `before_state` JSON NULL,
            `after_state` JSON NULL,
            `sql_statements` JSON NULL,
            `applied_by` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
            `applied_via` VARCHAR(20) NOT NULL,
            `dry_run` TINYINT(1) NOT NULL DEFAULT 0,
            `status` VARCHAR(16) NOT NULL,
            `created_at` DATETIME(3) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 4. entry_versions
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$entryVersions}` (
            `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            `collection_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `entry_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `version_no` INT NOT NULL,
            `status` VARCHAR(12) NOT NULL,
            `snapshot` JSON NOT NULL,
            `change_summary` VARCHAR(255) NULL,
            `actor_type` VARCHAR(16) NOT NULL,
            `actor_label` VARCHAR(190) NULL,
            `created_by` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
            `is_autosave` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` DATETIME(3) NOT NULL,
            UNIQUE KEY `uq_version` (`collection_id`, `entry_id`, `version_no`),
            KEY `ix_version_entry` (`collection_id`, `entry_id`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 5. saved_views
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$savedViews}` (
            `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            `user_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `collection_key` VARCHAR(64) NOT NULL,
            `name` VARCHAR(120) NOT NULL,
            `filters` JSON NOT NULL,
            `sort` VARCHAR(120) NULL,
            `columns` JSON NULL,
            `density` VARCHAR(10) NULL,
            `is_shared` TINYINT(1) NOT NULL DEFAULT 0,
            `position` INT NOT NULL DEFAULT 0,
            `created_at` DATETIME(3) NOT NULL,
            KEY `ix_saved_views_user` (`user_id`, `collection_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 6. webhooks
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$webhooks}` (
            `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            `name` VARCHAR(120) NOT NULL,
            `url` VARCHAR(2048) NOT NULL,
            `secret_enc` VARBINARY(255) NOT NULL,
            `events` JSON NOT NULL,
            `collections` JSON NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME(3) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 7. webhook_deliveries
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$webhookDeliveries}` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `webhook_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `event` VARCHAR(64) NOT NULL,
            `payload` JSON NOT NULL,
            `status` VARCHAR(12) NOT NULL,
            `attempts` SMALLINT NOT NULL DEFAULT 0,
            `response_code` SMALLINT NULL,
            `response_excerpt` VARCHAR(500) NULL,
            `next_attempt_at` DATETIME(3) NULL,
            `created_at` DATETIME(3) NOT NULL,
            KEY `ix_wh_status` (`status`, `next_attempt_at`),
            CONSTRAINT `fk_wh_deliveries_webhook` FOREIGN KEY (`webhook_id`) REFERENCES `{$webhooks}` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 8. entry_relations
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$entryRelations}` (
            `from_collection_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `from_entry_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `field_key` VARCHAR(64) NOT NULL,
            `to_collection_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `to_entry_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `position` INT NOT NULL DEFAULT 0,
            PRIMARY KEY (`from_collection_id`, `from_entry_id`, `field_key`, `to_entry_id`),
            KEY `ix_rel_reverse` (`to_collection_id`, `to_entry_id`, `field_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    public function down(): void
    {
        $collections = $this->connection->prefixTable('collections');
        $fields = $this->connection->prefixTable('fields');
        $schemaChanges = $this->connection->prefixTable('schema_changes');
        $entryVersions = $this->connection->prefixTable('entry_versions');
        $savedViews = $this->connection->prefixTable('saved_views');
        $webhooks = $this->connection->prefixTable('webhooks');
        $webhookDeliveries = $this->connection->prefixTable('webhook_deliveries');
        $entryRelations = $this->connection->prefixTable('entry_relations');

        $pdo = $this->connection->getPdo();

        // Drop any collection content tables
        try {
            $stmt = $pdo->query("SELECT `table_name` FROM `{$collections}`");
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                if (!empty($row['table_name'])) {
                    $cTable = $this->connection->prefixTable((string) $row['table_name']);
                    $pdo->exec("DROP TABLE IF EXISTS `{$cTable}`;");
                }
            }
        } catch (\Throwable $e) {
        }

        // Drop in reverse dependency order
        $pdo->exec("DROP TABLE IF EXISTS `{$entryRelations}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$webhookDeliveries}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$webhooks}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$savedViews}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$entryVersions}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$schemaChanges}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$fields}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$collections}`;");
    }
}
