<?php

declare(strict_types=1);

namespace BsCms\Migrations;

use BsCms\Database\Migration;
use PDO;

/**
 * Migration 0013: Add composite performance indexes for dashboard queries and entry listings.
 */
final class AddPerformanceIndexes extends Migration
{
    public function up(): void
    {
        $pdo = $this->connection->getPdo();
        $prefix = $this->connection->getPrefix();

        $collectionTables = [$prefix . 'c_pages', $prefix . 'c_posts'];
        foreach ($collectionTables as $table) {
            if (!$this->tableExists($table)) {
                continue;
            }

            $this->addIndexIfNotExists($table, 'ix_perf_del_status', '(`deleted_at`, `status`)');
            $this->addIndexIfNotExists($table, 'ix_perf_del_updated', '(`deleted_at`, `updated_at`)');
            $this->addIndexIfNotExists($table, 'ix_perf_del_created', '(`deleted_at`, `created_at`)');
        }

        $pendingTable = $this->connection->prefixTable('pending_changes');
        if ($this->tableExists($pendingTable)) {
            $this->addIndexIfNotExists($pendingTable, 'ix_perf_status_created', '(`status`, `created_at`)');
        }
    }

    public function down(): void
    {
        $prefix = $this->connection->getPrefix();
        $collectionTables = [$prefix . 'c_pages', $prefix . 'c_posts'];
        foreach ($collectionTables as $table) {
            if (!$this->tableExists($table)) {
                continue;
            }
            $this->dropIndexIfExists($table, 'ix_perf_del_status');
            $this->dropIndexIfExists($table, 'ix_perf_del_updated');
            $this->dropIndexIfExists($table, 'ix_perf_del_created');
        }

        $pendingTable = $this->connection->prefixTable('pending_changes');
        if ($this->tableExists($pendingTable)) {
            $this->dropIndexIfExists($pendingTable, 'ix_perf_status_created');
        }
    }

    private function tableExists(string $tableName): bool
    {
        $pdo = $this->connection->getPdo();
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($tableName));
            return $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    private function addIndexIfNotExists(string $tableName, string $indexName, string $columns): void
    {
        $pdo = $this->connection->getPdo();
        try {
            $stmt = $pdo->query("SHOW INDEX FROM `{$tableName}` WHERE `Key_name` = " . $pdo->quote($indexName));
            if ($stmt !== false && $stmt->fetch() === false) {
                $pdo->exec("ALTER TABLE `{$tableName}` ADD INDEX `{$indexName}` {$columns}");
            }
        } catch (\Throwable) {
        }
    }

    private function dropIndexIfExists(string $tableName, string $indexName): void
    {
        $pdo = $this->connection->getPdo();
        try {
            $stmt = $pdo->query("SHOW INDEX FROM `{$tableName}` WHERE `Key_name` = " . $pdo->quote($indexName));
            if ($stmt !== false && $stmt->fetch() !== false) {
                $pdo->exec("ALTER TABLE `{$tableName}` DROP INDEX `{$indexName}`");
            }
        } catch (\Throwable) {
        }
    }
}
