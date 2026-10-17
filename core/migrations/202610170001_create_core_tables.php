<?php

declare(strict_types=1);

namespace BsCms\Migrations;

use BsCms\Database\Migration;

/**
 * Core migration 0001: settings, jobs, rate_limits.
 * Adheres strictly to docs/01-data-model.md Section 7.
 */
final class CreateCoreTables extends Migration
{
    public function up(): void
    {
        $settings = $this->connection->prefixTable('settings');
        $jobs = $this->connection->prefixTable('jobs');
        $rateLimits = $this->connection->prefixTable('rate_limits');

        // settings table: handle PK, value JSON, is_secret TINYINT(1), updated_by CHAR(26), updated_at DATETIME(3)
        $this->connection->getPdo()->exec("CREATE TABLE IF NOT EXISTS `{$settings}` (
            `handle` VARCHAR(120) PRIMARY KEY,
            `value` JSON NOT NULL,
            `is_secret` TINYINT(1) NOT NULL DEFAULT 0,
            `updated_by` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
            `updated_at` DATETIME(3) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // jobs table: id BIGINT AUTO_INCREMENT PRIMARY KEY, queue VARCHAR(32), type VARCHAR(96), payload JSON...
        $this->connection->getPdo()->exec("CREATE TABLE IF NOT EXISTS `{$jobs}` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `queue` VARCHAR(32) NOT NULL DEFAULT 'default',
            `type` VARCHAR(96) NOT NULL,
            `payload` JSON NOT NULL,
            `status` VARCHAR(12) NOT NULL DEFAULT 'queued',
            `attempts` SMALLINT NOT NULL DEFAULT 0,
            `max_attempts` SMALLINT NOT NULL DEFAULT 5,
            `available_at` DATETIME(3) NOT NULL,
            `reserved_at` DATETIME(3) NULL,
            `last_error` TEXT NULL,
            `created_at` DATETIME(3) NOT NULL,
            KEY `ix_jobs_pick` (`status`, `queue`, `available_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // rate_limits table: bucket VARCHAR(190), window_start DATETIME, hits INT, PRIMARY KEY (bucket, window_start)
        $this->connection->getPdo()->exec("CREATE TABLE IF NOT EXISTS `{$rateLimits}` (
            `bucket` VARCHAR(190) NOT NULL,
            `window_start` DATETIME NOT NULL,
            `hits` INT NOT NULL DEFAULT 0,
            PRIMARY KEY (`bucket`, `window_start`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    public function down(): void
    {
        $settings = $this->connection->prefixTable('settings');
        $jobs = $this->connection->prefixTable('jobs');
        $rateLimits = $this->connection->prefixTable('rate_limits');

        $this->connection->getPdo()->exec("DROP TABLE IF EXISTS `{$rateLimits}`;");
        $this->connection->getPdo()->exec("DROP TABLE IF EXISTS `{$jobs}`;");
        $this->connection->getPdo()->exec("DROP TABLE IF EXISTS `{$settings}`;");
    }
}
