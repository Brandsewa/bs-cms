<?php

declare(strict_types=1);

namespace BsCms\Migrations;

use BsCms\Database\Migration;

/**
 * Migration 0004: Add totp_last_step column to users table for RFC 6238 replay protection.
 */
final class AddTotpLastStepToUsers extends Migration
{
    public function up(): void
    {
        $users = $this->connection->prefixTable('users');
        $pdo = $this->connection->getPdo();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS 
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'totp_last_step'"
        );
        $stmt->execute([$users]);
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE `{$users}` ADD COLUMN `totp_last_step` BIGINT NULL DEFAULT NULL AFTER `totp_enabled_at`");
        }
    }

    public function down(): void
    {
        $users = $this->connection->prefixTable('users');
        $pdo = $this->connection->getPdo();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS 
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'totp_last_step'"
        );
        $stmt->execute([$users]);
        if ((int) $stmt->fetchColumn() > 0) {
            $pdo->exec("ALTER TABLE `{$users}` DROP COLUMN `totp_last_step`");
        }
    }
}
