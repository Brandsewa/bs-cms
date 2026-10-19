<?php

declare(strict_types=1);

namespace BsCms\Migrations;

use BsCms\Database\Migration;

/**
 * Migration 0008: Create publishing pipeline and discoverability tables.
 * Adheres strictly to docs/01-data-model.md Sections 6 and 7 (Publishing pipeline tables).
 *
 * Tables created:
 * - deployments
 * - preview_tokens
 * - build_tokens
 * - crawler_policy_versions
 * - index_notifications
 * - bot_log
 */
final class CreatePublishingPipelineAndDiscoverabilityTables extends Migration
{
    public function up(): void
    {
        $deployments = $this->connection->prefixTable('deployments');
        $previewTokens = $this->connection->prefixTable('preview_tokens');
        $buildTokens = $this->connection->prefixTable('build_tokens');
        $crawlerPolicyVersions = $this->connection->prefixTable('crawler_policy_versions');
        $indexNotifications = $this->connection->prefixTable('index_notifications');
        $botLog = $this->connection->prefixTable('bot_log');

        $pdo = $this->connection->getPdo();

        // 1. deployments
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$deployments}` (
            `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            `trigger_type` VARCHAR(16) NOT NULL,
            `status` VARCHAR(12) NOT NULL DEFAULT 'queued',
            `target` VARCHAR(32) NOT NULL,
            `changed_urls` JSON NULL,
            `requested_by` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
            `dispatched_at` DATETIME(3) NULL,
            `finished_at` DATETIME(3) NULL,
            `build_log_url` VARCHAR(1024) NULL,
            `error` VARCHAR(1000) NULL,
            `callback_token_hash` CHAR(64) NOT NULL,
            `created_at` DATETIME(3) NOT NULL,
            KEY `ix_deploy_status` (`status`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 2. preview_tokens
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$previewTokens}` (
            `token_hash` CHAR(64) PRIMARY KEY,
            `collection_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `entry_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `created_by` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `expires_at` DATETIME(3) NOT NULL,
            `created_at` DATETIME(3) NOT NULL,
            KEY `ix_prevtoken_entry` (`collection_id`, `entry_id`),
            KEY `ix_prevtoken_expires` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 3. build_tokens
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$buildTokens}` (
            `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            `name` VARCHAR(120) NOT NULL,
            `token_prefix` CHAR(8) NOT NULL,
            `token_hash` CHAR(64) NOT NULL,
            `last_used_at` DATETIME(3) NULL,
            `revoked_at` DATETIME(3) NULL,
            `created_at` DATETIME(3) NOT NULL,
            UNIQUE KEY `uq_buildtoken` (`token_hash`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 4. crawler_policy_versions
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$crawlerPolicyVersions}` (
            `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            `preset` VARCHAR(24) NOT NULL,
            `policy` JSON NOT NULL,
            `robots_txt` MEDIUMTEXT NOT NULL,
            `changed_by` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
            `change_note` VARCHAR(500) NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` DATETIME(3) NOT NULL,
            KEY `ix_cpv_active` (`is_active`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 5. index_notifications
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$indexNotifications}` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `engine` VARCHAR(24) NOT NULL,
            `urls` JSON NOT NULL,
            `status` VARCHAR(12) NOT NULL,
            `response_code` SMALLINT NULL,
            `error` VARCHAR(500) NULL,
            `created_at` DATETIME(3) NOT NULL,
            KEY `ix_idxnotif_created` (`created_at`),
            KEY `ix_idxnotif_engine` (`engine`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 6. bot_log
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$botLog}` (
            `day` DATE NOT NULL,
            `bot` VARCHAR(64) NOT NULL,
            `verified` TINYINT(1) NOT NULL DEFAULT 0,
            `path_group` VARCHAR(64) NOT NULL,
            `hits` INT NOT NULL DEFAULT 0,
            PRIMARY KEY (`day`, `bot`, `verified`, `path_group`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    public function down(): void
    {
        $deployments = $this->connection->prefixTable('deployments');
        $previewTokens = $this->connection->prefixTable('preview_tokens');
        $buildTokens = $this->connection->prefixTable('build_tokens');
        $crawlerPolicyVersions = $this->connection->prefixTable('crawler_policy_versions');
        $indexNotifications = $this->connection->prefixTable('index_notifications');
        $botLog = $this->connection->prefixTable('bot_log');

        $pdo = $this->connection->getPdo();

        $pdo->exec("DROP TABLE IF EXISTS `{$botLog}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$indexNotifications}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$crawlerPolicyVersions}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$buildTokens}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$previewTokens}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$deployments}`;");
    }
}
