<?php

declare(strict_types=1);

namespace BsCms\Schema;

use BsCms\Database\Connection;
use BsCms\Schema\FieldTypes\StorageDescriptor;
use BsCms\Security\Ulid;
use InvalidArgumentException;

final class SchemaPlanner
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CollectionRegistry $registry,
        private readonly FieldTypeRegistry $fieldTypeRegistry
    ) {
    }

    /**
     * Plan changes between existing registry and target collection definition.
     *
     * @param array<string, mixed> $targetDefinition
     */
    public function plan(array $targetDefinition): SchemaPlan
    {
        $handle = (string) ($targetDefinition['handle'] ?? '');
        IdentifierValidator::validateCollectionHandle($handle);

        $existing = $this->registry->has($handle) ? $this->registry->get($handle) : null;
        $planId = Ulid::generate();

        if ($existing === null) {
            return $this->planCreateCollection($planId, $targetDefinition);
        }

        return $this->planAlterCollection($planId, $existing, $targetDefinition);
    }

    /**
     * @param array<string, mixed> $target
     */
    private function planCreateCollection(string $planId, array $target): SchemaPlan
    {
        $handle = (string) $target['handle'];
        $tableName = 'c_' . $handle;
        $prefix = $this->connection->getPrefix();
        $fullTable = $prefix . $tableName;
        $escapedFullTable = IdentifierValidator::escapeIdentifier($fullTable);

        $changes = [
            [
                'type' => 'create_collection',
                'handle' => $handle,
                'table_name' => $tableName,
            ],
        ];

        $fields = $target['fields'] ?? [];
        $columnsSql = [
            '`id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY',
            '`slug` VARCHAR(191) NOT NULL',
            '`title` VARCHAR(255) NOT NULL',
            '`status` VARCHAR(12) NOT NULL DEFAULT \'draft\'',
            '`locale` CHAR(5) NOT NULL DEFAULT \'en\'',
            '`parent_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL',
            '`position` INT NOT NULL DEFAULT 0',
            '`author_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL',
            '`published_at` DATETIME(3) NULL',
            '`scheduled_at` DATETIME(3) NULL',
            '`seo` JSON NULL',
            '`data` JSON NOT NULL',
            '`search_text` MEDIUMTEXT NULL',
            '`created_at` DATETIME(3) NOT NULL',
            '`created_by` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL',
            '`updated_at` DATETIME(3) NOT NULL',
            '`updated_by` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL',
            '`deleted_at` DATETIME(3) NULL',
        ];

        $indexesSql = [
            "UNIQUE KEY `uq_{$tableName}_slug` (`slug`, `locale`)",
            "KEY `ix_{$tableName}_status_pub` (`status`, `published_at`)",
            "KEY `ix_{$tableName}_updated` (`updated_at`)",
            "KEY `ix_{$tableName}_author` (`author_id`)",
            "FULLTEXT KEY `ft_{$tableName}` (`title`)",
        ];

        // Process custom fields
        foreach ($fields as $fieldHandle => $fieldDef) {
            $fHandle = is_string($fieldHandle) ? $fieldHandle : (string) ($fieldDef['handle'] ?? '');
            IdentifierValidator::validateFieldHandle($fHandle);

            $fTypeStr = (string) ($fieldDef['type'] ?? 'text');
            $fType = $this->fieldTypeRegistry->get($fTypeStr);

            $config = (array) ($fieldDef['config'] ?? []);
            $configErrors = $fType->validateConfig($config);
            if (!empty($configErrors)) {
                $firstErr = reset($configErrors);
                throw new InvalidArgumentException("Field '{$fHandle}' config error: {$firstErr}");
            }

            $isFilterable = (bool) ($fieldDef['is_filterable'] ?? false);
            $isSortable = (bool) ($fieldDef['is_sortable'] ?? false);

            if ($isFilterable || $isSortable) {
                $storage = $fType->storage($config);
                $colType = $storage->columnType ?? 'VARCHAR(255)';
                $escapedCol = IdentifierValidator::escapeIdentifier($fHandle);
                $escapedIdx = IdentifierValidator::escapeIdentifier('ix_' . $tableName . '_' . $fHandle);

                $columnsSql[] = "{$escapedCol} {$colType} GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`data`, '$.{$fHandle}'))) VIRTUAL";
                $indexesSql[] = "KEY {$escapedIdx} ({$escapedCol})";
            }

            $changes[] = [
                'type' => 'add_field',
                'field_handle' => $fHandle,
                'field_type' => $fTypeStr,
                'is_filterable' => $isFilterable,
                'is_sortable' => $isSortable,
            ];
        }

        $allDefs = array_merge($columnsSql, $indexesSql);
        $createSql = "CREATE TABLE IF NOT EXISTS {$escapedFullTable} (\n  " . implode(",\n  ", $allDefs) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;";

        return new SchemaPlan(
            $planId,
            $handle,
            $changes,
            [$createSql],
            ['data_loss' => false, 'long_running' => false, 'locks_table' => false],
            0
        );
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $target
     */
    private function planAlterCollection(string $planId, array $existing, array $target): SchemaPlan
    {
        $handle = (string) $target['handle'];
        $tableName = 'c_' . $handle;
        $prefix = $this->connection->getPrefix();
        $fullTable = $prefix . $tableName;
        $escapedFullTable = IdentifierValidator::escapeIdentifier($fullTable);

        $changes = [];
        $sqlStatements = [];
        $dataLoss = false;

        $existingFields = (array) ($existing['fields'] ?? []);
        $targetFields = (array) ($target['fields'] ?? []);

        // Check for added or modified fields
        foreach ($targetFields as $fHandle => $fieldDef) {
            $handleStr = is_string($fHandle) ? $fHandle : (string) ($fieldDef['handle'] ?? '');
            IdentifierValidator::validateFieldHandle($handleStr);

            $fTypeStr = (string) ($fieldDef['type'] ?? 'text');
            $fType = $this->fieldTypeRegistry->get($fTypeStr);

            $config = (array) ($fieldDef['config'] ?? []);
            $configErrors = $fType->validateConfig($config);
            if (!empty($configErrors)) {
                $firstErr = reset($configErrors);
                throw new InvalidArgumentException("Field '{$handleStr}' config error: {$firstErr}");
            }

            $isFilterable = (bool) ($fieldDef['is_filterable'] ?? false);
            $isSortable = (bool) ($fieldDef['is_sortable'] ?? false);

            if (!isset($existingFields[$handleStr])) {
                // Add field
                $changes[] = [
                    'type' => 'add_field',
                    'field_handle' => $handleStr,
                    'field_type' => $fTypeStr,
                ];

                if ($isFilterable || $isSortable) {
                    $storage = $fType->storage($config);
                    $colType = $storage->columnType ?? 'VARCHAR(255)';
                    $escapedCol = IdentifierValidator::escapeIdentifier($handleStr);
                    $escapedIdx = IdentifierValidator::escapeIdentifier('ix_' . $tableName . '_' . $handleStr);

                    $sqlStatements[] = "ALTER TABLE {$escapedFullTable} ADD COLUMN {$escapedCol} {$colType} GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`data`, '$.{$handleStr}'))) VIRTUAL, ADD KEY {$escapedIdx} ({$escapedCol});";
                }
            } else {
                // Existing field: check type compatibility
                $oldField = $existingFields[$handleStr];
                $oldType = (string) ($oldField['type'] ?? 'text');
                if ($oldType !== $fTypeStr) {
                    $this->assertTypeCompatibility($handleStr, $oldType, $fTypeStr);
                }

                $oldFilterable = (bool) ($oldField['is_filterable'] ?? false);
                $oldSortable = (bool) ($oldField['is_sortable'] ?? false);

                // If index/virtual column needs to be added
                if (($isFilterable || $isSortable) && !($oldFilterable || $oldSortable)) {
                    $storage = $fType->storage($config);
                    $colType = $storage->columnType ?? 'VARCHAR(255)';
                    $escapedCol = IdentifierValidator::escapeIdentifier($handleStr);
                    $escapedIdx = IdentifierValidator::escapeIdentifier('ix_' . $tableName . '_' . $handleStr);

                    $changes[] = [
                        'type' => 'add_index',
                        'field_handle' => $handleStr,
                    ];
                    $sqlStatements[] = "ALTER TABLE {$escapedFullTable} ADD COLUMN {$escapedCol} {$colType} GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`data`, '$.{$handleStr}'))) VIRTUAL, ADD KEY {$escapedIdx} ({$escapedCol});";
                } elseif (!($isFilterable || $isSortable) && ($oldFilterable || $oldSortable)) {
                    // Remove virtual column and index
                    $escapedCol = IdentifierValidator::escapeIdentifier($handleStr);
                    $escapedIdx = IdentifierValidator::escapeIdentifier('ix_' . $tableName . '_' . $handleStr);

                    $changes[] = [
                        'type' => 'drop_index',
                        'field_handle' => $handleStr,
                    ];
                    $sqlStatements[] = "ALTER TABLE {$escapedFullTable} DROP KEY {$escapedIdx}, DROP COLUMN {$escapedCol};";
                }
            }
        }

        // Check for removed fields: they are hidden, NOT dropped (data kept)
        foreach ($existingFields as $oldHandle => $oldDef) {
            if (!isset($targetFields[$oldHandle])) {
                $changes[] = [
                    'type' => 'hide_field',
                    'field_handle' => $oldHandle,
                ];
            }
        }

        return new SchemaPlan(
            $planId,
            $handle,
            $changes,
            $sqlStatements,
            ['data_loss' => $dataLoss, 'long_running' => false, 'locks_table' => !empty($sqlStatements)],
            0
        );
    }

    /**
     * Changing a field type is allowed only between compatible types:
     * - text <-> textarea
     * - enum -> text
     * - number int -> decimal
     */
    private function assertTypeCompatibility(string $fieldHandle, string $from, string $to): void
    {
        $allowed = [
            'text' => ['textarea'],
            'textarea' => ['text'],
            'enum' => ['text'],
        ];

        if (isset($allowed[$from]) && in_array($to, $allowed[$from], true)) {
            return;
        }

        throw new InvalidArgumentException(
            "Incompatible field type change for '{$fieldHandle}': cannot convert from '{$from}' to '{$to}'. Create a new field instead."
        );
    }
}
