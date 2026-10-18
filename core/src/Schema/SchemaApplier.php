<?php

declare(strict_types=1);

namespace BsCms\Schema;

use BsCms\Database\Connection;
use BsCms\Security\Ulid;
use BsCms\Support\Clock;
use Throwable;

final class SchemaApplier
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CollectionRegistry $registry,
        private readonly SchemaLock $lock,
        private readonly ?Clock $clock = null
    ) {
    }

    /**
     * Apply a proposed schema plan holding the distributed GET_LOCK('bs_schema', 30).
     * Bumps schema_version, updates collections & fields tables, records schema_changes row,
     * executes DDL, and invalidates the compiled cache.
     *
     * @param array<string, mixed> $targetDefinition The full collection definition
     */
    public function apply(
        SchemaPlan $plan,
        array $targetDefinition,
        ?string $appliedBy = null,
        string $appliedVia = 'cli'
    ): void {
        $this->lock->withLock(function () use ($plan, $targetDefinition, $appliedBy, $appliedVia) {
            $prefix = $this->connection->getPrefix();
            $now = $this->clock !== null ? $this->clock->now()->format('Y-m-d H:i:s.v') : date('Y-m-d H:i:s.v');
            $changeId = $plan->id;
            $handle = $plan->collectionHandle ?? (string) $targetDefinition['handle'];

            // Record proposed schema change
            $this->connection->execute(
                "INSERT INTO `{$prefix}schema_changes` (
                    `id`, `collection_id`, `change_type`, `before_state`, `after_state`,
                    `sql_statements`, `applied_by`, `applied_via`, `dry_run`, `status`, `created_at`
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 'proposed', ?)",
                [
                    $changeId,
                    null,
                    $plan->changes[0]['type'] ?? 'alter_collection',
                    null,
                    json_encode($targetDefinition, JSON_THROW_ON_ERROR),
                    json_encode($plan->sqlStatements, JSON_THROW_ON_ERROR),
                    $appliedBy,
                    $appliedVia,
                    $now,
                ]
            );

            try {
                // Execute DDL statements
                foreach ($plan->sqlStatements as $sql) {
                    $this->connection->getPdo()->exec($sql);
                }

                // Update collections and fields tables inside a transaction
                $this->connection->transaction(function (Connection $conn) use ($targetDefinition, $handle, $prefix, $now) {
                    $existingColStmt = $conn->execute(
                        "SELECT `id`, `schema_version` FROM `{$prefix}collections` WHERE `handle` = ?",
                        [$handle]
                    );
                    $existingCol = $existingColStmt->fetch();

                    $collectionId = $existingCol ? (string) $existingCol['id'] : Ulid::generate();
                    $version = $existingCol ? ((int) $existingCol['schema_version'] + 1) : 1;

                    $tableName = 'c_' . $handle;
                    $kind = (string) ($targetDefinition['kind'] ?? 'collection');
                    $labelSingular = (string) ($targetDefinition['label_singular'] ?? ucfirst($handle));
                    $labelPlural = (string) ($targetDefinition['label_plural'] ?? ucfirst($handle) . 's');
                    $description = $targetDefinition['description'] ?? null;
                    $icon = $targetDefinition['icon'] ?? null;
                    $isSystem = !empty($targetDefinition['is_system']) ? 1 : 0;
                    $hasPublicPages = !isset($targetDefinition['has_public_pages']) || !empty($targetDefinition['has_public_pages']) ? 1 : 0;
                    $routePattern = $targetDefinition['route_pattern'] ?? null;
                    $listRoutePattern = $targetDefinition['list_route_pattern'] ?? null;
                    $features = json_encode($targetDefinition['features'] ?? [], JSON_THROW_ON_ERROR);
                    $titleField = (string) ($targetDefinition['title_field'] ?? 'title');
                    $slugSource = $targetDefinition['slug_source'] ?? 'title';
                    $defaultSort = (string) ($targetDefinition['default_sort'] ?? '-updated_at');
                    $schemaOrg = isset($targetDefinition['schema_org']) ? json_encode($targetDefinition['schema_org'], JSON_THROW_ON_ERROR) : null;
                    $listColumns = isset($targetDefinition['list_columns']) ? json_encode($targetDefinition['list_columns'], JSON_THROW_ON_ERROR) : null;
                    $pluginKey = $targetDefinition['plugin_key'] ?? null;

                    if ($existingCol) {
                        $conn->execute(
                            "UPDATE `{$prefix}collections` SET
                                `kind` = ?, `label_singular` = ?, `label_plural` = ?, `description` = ?,
                                `icon` = ?, `is_system` = ?, `has_public_pages` = ?, `route_pattern` = ?,
                                `list_route_pattern` = ?, `features` = ?, `title_field` = ?, `slug_source` = ?,
                                `default_sort` = ?, `schema_org` = ?, `list_columns` = ?, `plugin_key` = ?,
                                `schema_version` = ?, `updated_at` = ?
                            WHERE `id` = ?",
                            [
                                $kind, $labelSingular, $labelPlural, $description,
                                $icon, $isSystem, $hasPublicPages, $routePattern,
                                $listRoutePattern, $features, $titleField, $slugSource,
                                $defaultSort, $schemaOrg, $listColumns, $pluginKey,
                                $version, $now, $collectionId,
                            ]
                        );
                    } else {
                        $conn->execute(
                            "INSERT INTO `{$prefix}collections` (
                                `id`, `handle`, `kind`, `label_singular`, `label_plural`, `description`,
                                `icon`, `table_name`, `is_system`, `has_public_pages`, `route_pattern`,
                                `list_route_pattern`, `features`, `title_field`, `slug_source`,
                                `default_sort`, `schema_org`, `list_columns`, `plugin_key`,
                                `schema_version`, `created_at`, `updated_at`
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                            [
                                $collectionId, $handle, $kind, $labelSingular, $labelPlural, $description,
                                $icon, $tableName, $isSystem, $hasPublicPages, $routePattern,
                                $listRoutePattern, $features, $titleField, $slugSource,
                                $defaultSort, $schemaOrg, $listColumns, $pluginKey,
                                $version, $now, $now,
                            ]
                        );
                    }

                    // Update fields
                    $fields = (array) ($targetDefinition['fields'] ?? []);
                    $pos = 0;
                    foreach ($fields as $fHandle => $fDef) {
                        ++$pos;
                        $fieldHandle = is_string($fHandle) ? $fHandle : (string) ($fDef['handle'] ?? '');
                        $fLabel = (string) ($fDef['label'] ?? ucfirst($fieldHandle));
                        $fType = (string) ($fDef['type'] ?? 'text');
                        $fConfig = json_encode($fDef['config'] ?? [], JSON_THROW_ON_ERROR);
                        $fRequired = !empty($fDef['required']) ? 1 : 0;
                        $fUnique = !empty($fDef['is_unique']) ? 1 : 0;
                        $fFilterable = !empty($fDef['is_filterable']) ? 1 : 0;
                        $fSortable = !empty($fDef['is_sortable']) ? 1 : 0;
                        $fSearchable = !empty($fDef['is_searchable']) ? 1 : 0;
                        $fShowInList = !empty($fDef['show_in_list']) ? 1 : 0;
                        $fDefault = isset($fDef['default_value']) ? json_encode($fDef['default_value'], JSON_THROW_ON_ERROR) : null;
                        $fHelp = $fDef['help_text'] ?? null;
                        $fPlaceholder = $fDef['placeholder'] ?? null;
                        $fGroup = $fDef['group_key'] ?? null;
                        $fWidth = (string) ($fDef['width'] ?? 'full');
                        $fValidation = isset($fDef['validation']) ? json_encode($fDef['validation'], JSON_THROW_ON_ERROR) : null;
                        $fConditional = isset($fDef['conditional']) ? json_encode($fDef['conditional'], JSON_THROW_ON_ERROR) : null;
                        $fStorage = (string) ($fDef['storage'] ?? 'json');
                        $fColumnName = $fDef['column_name'] ?? null;

                        $existingFieldStmt = $conn->execute(
                            "SELECT `id` FROM `{$prefix}fields` WHERE `collection_id` = ? AND `handle` = ?",
                            [$collectionId, $fieldHandle]
                        );
                        $existingField = $existingFieldStmt->fetch();

                        if ($existingField) {
                            $conn->execute(
                                "UPDATE `{$prefix}fields` SET
                                    `label` = ?, `type` = ?, `config` = ?, `required` = ?,
                                    `is_unique` = ?, `is_filterable` = ?, `is_sortable` = ?,
                                    `is_searchable` = ?, `show_in_list` = ?, `default_value` = ?,
                                    `help_text` = ?, `placeholder` = ?, `group_key` = ?,
                                    `position` = ?, `width` = ?, `validation` = ?,
                                    `conditional` = ?, `storage` = ?, `column_name` = ?,
                                    `updated_at` = ?
                                WHERE `id` = ?",
                                [
                                    $fLabel, $fType, $fConfig, $fRequired,
                                    $fUnique, $fFilterable, $fSortable,
                                    $fSearchable, $fShowInList, $fDefault,
                                    $fHelp, $fPlaceholder, $fGroup,
                                    $pos, $fWidth, $fValidation,
                                    $fConditional, $fStorage, $fColumnName,
                                    $now, (string) $existingField['id'],
                                ]
                            );
                        } else {
                            $fieldId = Ulid::generate();
                            $conn->execute(
                                "INSERT INTO `{$prefix}fields` (
                                    `id`, `collection_id`, `handle`, `label`, `type`, `config`,
                                    `required`, `is_unique`, `is_filterable`, `is_sortable`,
                                    `is_searchable`, `show_in_list`, `default_value`, `help_text`,
                                    `placeholder`, `group_key`, `position`, `width`,
                                    `validation`, `conditional`, `storage`, `column_name`,
                                    `created_at`, `updated_at`
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                                [
                                    $fieldId, $collectionId, $fieldHandle, $fLabel, $fType, $fConfig,
                                    $fRequired, $fUnique, $fFilterable, $fSortable,
                                    $fSearchable, $fShowInList, $fDefault, $fHelp,
                                    $fPlaceholder, $fGroup, $pos, $fWidth,
                                    $fValidation, $fConditional, $fStorage, $fColumnName,
                                    $now, $now,
                                ]
                            );
                        }
                    }
                });

                // Update schema_changes status to applied
                $this->connection->execute(
                    "UPDATE `{$prefix}schema_changes` SET `status` = 'applied' WHERE `id` = ?",
                    [$changeId]
                );

                // Invalidate cache
                $this->registry->invalidate();
            } catch (Throwable $e) {
                // Record failure
                $this->connection->execute(
                    "UPDATE `{$prefix}schema_changes` SET `status` = 'failed' WHERE `id` = ?",
                    [$changeId]
                );
                $this->registry->invalidate();
                throw $e;
            }
        });
    }
}
