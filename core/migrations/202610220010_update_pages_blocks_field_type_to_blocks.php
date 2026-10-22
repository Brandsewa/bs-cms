<?php

declare(strict_types=1);

namespace BsCms\Migrations;

use BsCms\Database\Migration;

/**
 * Migration 0010: Update pages.blocks field definition from type 'json' to 'blocks'.
 */
final class UpdatePagesBlocksFieldTypeToBlocks extends Migration
{
    public function up(): void
    {
        $collections = $this->connection->prefixTable('collections');
        $fields = $this->connection->prefixTable('fields');
        $pdo = $this->connection->getPdo();

        // 1. Find pages collection ID
        $stmt = $pdo->prepare("SELECT `id` FROM `{$collections}` WHERE `handle` = 'pages' LIMIT 1");
        $stmt->execute();
        $pagesColId = $stmt->fetchColumn();

        if ($pagesColId) {
            // Update fields table row for blocks field on pages collection
            $updateStmt = $pdo->prepare("UPDATE `{$fields}` SET `type` = 'blocks' WHERE `collection_id` = ? AND `handle` = 'blocks'");
            $updateStmt->execute([$pagesColId]);
        }
    }

    public function down(): void
    {
        $collections = $this->connection->prefixTable('collections');
        $fields = $this->connection->prefixTable('fields');
        $pdo = $this->connection->getPdo();

        $stmt = $pdo->prepare("SELECT `id` FROM `{$collections}` WHERE `handle` = 'pages' LIMIT 1");
        $stmt->execute();
        $pagesColId = $stmt->fetchColumn();

        if ($pagesColId) {
            $updateStmt = $pdo->prepare("UPDATE `{$fields}` SET `type` = 'json' WHERE `collection_id` = ? AND `handle` = 'blocks'");
            $updateStmt->execute([$pagesColId]);
        }
    }
}
