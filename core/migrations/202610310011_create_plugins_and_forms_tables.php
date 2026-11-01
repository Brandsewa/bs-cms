<?php

declare(strict_types=1);

namespace BsCms\Migrations;

use BsCms\Database\Migration;

/**
 * Migration 0011: Create plugins, form_definitions, and form_submissions tables.
 * Conforms to docs/01-data-model.md Section 7 and Marketplace & Form Plugins specification.
 */
final class CreatePluginsAndFormsTables extends Migration
{
    public function up(): void
    {
        $plugins = $this->connection->prefixTable('plugins');
        $formDefinitions = $this->connection->prefixTable('form_definitions');
        $formSubmissions = $this->connection->prefixTable('form_submissions');

        $pdo = $this->connection->getPdo();

        // 1. Plugins Table
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$plugins}` (
            `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            `handle` VARCHAR(64) NOT NULL,
            `version` VARCHAR(32) NOT NULL,
            `is_enabled` TINYINT(1) NOT NULL DEFAULT 0,
            `config` JSON NULL,
            `license_key_enc` VARBINARY(512) NULL,
            `installed_at` DATETIME(3) NOT NULL,
            UNIQUE KEY `uq_plugin_key` (`handle`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 2. Form Definitions Table
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$formDefinitions}` (
            `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            `handle` VARCHAR(64) NOT NULL,
            `title` VARCHAR(190) NOT NULL,
            `description` TEXT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
            `fields` JSON NOT NULL,
            `settings` JSON NOT NULL,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NOT NULL,
            UNIQUE KEY `uq_form_handle` (`handle`),
            KEY `ix_form_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 3. Form Submissions Table
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$formSubmissions}` (
            `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            `form_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `form_handle` VARCHAR(64) NOT NULL,
            `data` JSON NOT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'unread',
            `ip_address` VARCHAR(45) NULL,
            `user_agent` VARCHAR(512) NULL,
            `created_at` DATETIME(3) NOT NULL,
            KEY `ix_sub_form` (`form_id`, `created_at`),
            KEY `ix_sub_handle` (`form_handle`, `created_at`),
            KEY `ix_sub_status` (`status`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    public function down(): void
    {
        $plugins = $this->connection->prefixTable('plugins');
        $formDefinitions = $this->connection->prefixTable('form_definitions');
        $formSubmissions = $this->connection->prefixTable('form_submissions');

        $pdo = $this->connection->getPdo();

        $pdo->exec("DROP TABLE IF EXISTS `{$formSubmissions}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$formDefinitions}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$plugins}`;");
    }
}
