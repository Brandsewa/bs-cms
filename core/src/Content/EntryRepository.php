<?php

declare(strict_types=1);

namespace BsCms\Content;

use BsCms\Database\Connection;
use BsCms\Database\QueryBuilder;
use BsCms\Schema\CollectionRegistry;
use BsCms\Schema\FieldTypeRegistry;
use BsCms\Security\Ulid;
use BsCms\Support\Clock;
use InvalidArgumentException;
use RuntimeException;

final class EntryRepository
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_IN_REVIEW = 'in_review';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    public function __construct(
        private readonly Connection $connection,
        private readonly CollectionRegistry $collectionRegistry,
        private readonly FieldTypeRegistry $fieldTypeRegistry,
        private readonly SlugService $slugService,
        private readonly HierarchyValidator $hierarchyValidator,
        private readonly VersionManager $versionManager,
        private readonly RelationManager $relationManager,
        private readonly ?Clock $clock = null
    ) {
    }

    /**
     * Create a new entry in a collection.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(
        string $collectionHandle,
        array $input,
        string $actorType = 'user',
        ?string $actorLabel = null,
        ?string $createdBy = null
    ): array {
        $collection = $this->collectionRegistry->get($collectionHandle);
        $collectionId = (string) $collection['id'];
        $kind = (string) ($collection['kind'] ?? 'collection');
        $prefix = $this->connection->getPrefix();
        $tableName = $prefix . 'c_' . $collectionHandle;
        $now = $this->clock !== null ? $this->clock->now()->format('Y-m-d H:i:s.v') : date('Y-m-d H:i:s.v');

        if ($kind === 'single') {
            // Check if singleton already exists
            $stmt = $this->connection->execute("SELECT `id` FROM `{$tableName}` LIMIT 1");
            if ($stmt->fetch()) {
                throw new RuntimeException("Singleton collection '{$collectionHandle}' already has a record. Use update instead.");
            }
        }

        $id = Ulid::generate();
        $titleField = (string) ($collection['title_field'] ?? 'title');
        $title = trim((string) ($input[$titleField] ?? $input['title'] ?? 'Untitled'));

        $locale = (string) ($input['locale'] ?? 'en');
        $slug = isset($input['slug']) && trim((string) $input['slug']) !== ''
            ? $this->slugService->slugify((string) $input['slug'])
            : $this->slugService->generateUniqueSlug($collectionHandle, $title, $locale);

        $status = (string) ($input['status'] ?? self::STATUS_DRAFT);
        $this->validateStatusTransition(null, $status, $input);

        $parentId = $input['parent_id'] ?? null;
        if (!empty($collection['features']['hierarchy'])) {
            $this->hierarchyValidator->validateParent($collectionHandle, $parentId, $id);
        }

        $position = (int) ($input['position'] ?? 0);
        $authorId = $input['author_id'] ?? $createdBy;
        $publishedAt = ($status === self::STATUS_PUBLISHED) ? ($input['published_at'] ?? $now) : null;
        $scheduledAt = ($status === self::STATUS_SCHEDULED) ? ($input['scheduled_at'] ?? null) : null;
        $seo = isset($input['seo']) && is_array($input['seo']) ? json_encode($input['seo'], JSON_THROW_ON_ERROR) : null;

        // Process fields into column vs data JSON
        [$columnValues, $dataValues, $relationsToSync, $searchText] = $this->partitionFields(
            $collection,
            $input,
            $title
        );

        $sql = "INSERT INTO `{$tableName}` (
            `id`, `slug`, `title`, `status`, `locale`, `parent_id`, `position`, `author_id`,
            `published_at`, `scheduled_at`, `seo`, `data`, `search_text`,
            `created_at`, `created_by`, `updated_at`, `updated_by`
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $this->connection->execute($sql, [
            $id,
            $slug,
            $title,
            $status,
            $locale,
            $parentId,
            $position,
            $authorId,
            $publishedAt,
            $scheduledAt,
            $seo,
            json_encode($dataValues, JSON_THROW_ON_ERROR),
            $searchText,
            $now,
            $createdBy,
            $now,
            $createdBy,
        ]);

        // Sync relations
        foreach ($relationsToSync as $rel) {
            $this->relationManager->sync(
                $collectionId,
                $id,
                $rel['field_key'],
                $rel['target_collection_id'],
                $rel['target_entry_ids']
            );
        }

        // Record initial version
        $snapshot = array_merge($input, [
            'id' => $id,
            'slug' => $slug,
            'title' => $title,
            'status' => $status,
            'locale' => $locale,
            'parent_id' => $parentId,
            'position' => $position,
            'author_id' => $authorId,
            'published_at' => $publishedAt,
            'scheduled_at' => $scheduledAt,
            'data' => $dataValues,
        ]);

        $versionNo = $this->versionManager->recordVersion(
            $collectionId,
            $id,
            $status,
            $snapshot,
            'Entry created',
            $actorType,
            $actorLabel,
            $createdBy
        );

        return $this->find($collectionHandle, $id) ?? [];
    }

    /**
     * Update an entry with optimistic concurrency check.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(
        string $collectionHandle,
        string $id,
        array $input,
        ?string $changeSummary = null,
        ?int $expectedVersion = null,
        string $actorType = 'user',
        ?string $actorLabel = null,
        ?string $updatedBy = null,
        bool $isAutosave = false
    ): array {
        $collection = $this->collectionRegistry->get($collectionHandle);
        $collectionId = (string) $collection['id'];
        $prefix = $this->connection->getPrefix();
        $tableName = $prefix . 'c_' . $collectionHandle;
        $now = $this->clock !== null ? $this->clock->now()->format('Y-m-d H:i:s.v') : date('Y-m-d H:i:s.v');

        $existing = $this->find($collectionHandle, $id);
        if (!$existing) {
            throw new RuntimeException("Entry '{$id}' not found in '{$collectionHandle}'.");
        }

        // Check optimistic concurrency version
        if ($expectedVersion !== null && (int) $existing['version'] !== $expectedVersion) {
            throw new OptimisticConcurrencyException(
                "Entry '{$id}' was modified by another request. Expected version {$expectedVersion}, current version is {$existing['version']}.",
                $existing
            );
        }

        $oldStatus = (string) $existing['status'];
        $newStatus = (string) ($input['status'] ?? $oldStatus);
        $this->validateStatusTransition($oldStatus, $newStatus, $input);

        $titleField = (string) ($collection['title_field'] ?? 'title');
        $title = isset($input[$titleField]) ? trim((string) $input[$titleField]) : (string) $existing['title'];

        $slug = $existing['slug'];
        if (isset($input['slug']) && trim((string) $input['slug']) !== '' && $input['slug'] !== $existing['slug']) {
            $slug = $this->slugService->generateUniqueSlug($collectionHandle, (string) $input['slug'], $existing['locale'], $id);
        }

        $parentId = array_key_exists('parent_id', $input) ? $input['parent_id'] : $existing['parent_id'];
        if (!empty($collection['features']['hierarchy'])) {
            $this->hierarchyValidator->validateParent($collectionHandle, $parentId, $id);
        }

        $position = isset($input['position']) ? (int) $input['position'] : (int) $existing['position'];
        $authorId = array_key_exists('author_id', $input) ? $input['author_id'] : $existing['author_id'];

        $publishedAt = $existing['published_at'];
        if ($newStatus === self::STATUS_PUBLISHED && $oldStatus !== self::STATUS_PUBLISHED) {
            $publishedAt = $input['published_at'] ?? $now;
        } elseif ($newStatus !== self::STATUS_PUBLISHED && $newStatus !== self::STATUS_ARCHIVED) {
            $publishedAt = null;
        }

        $scheduledAt = ($newStatus === self::STATUS_SCHEDULED) ? ($input['scheduled_at'] ?? $existing['scheduled_at']) : null;
        $seo = array_key_exists('seo', $input) && is_array($input['seo'])
            ? json_encode($input['seo'], JSON_THROW_ON_ERROR)
            : (isset($existing['seo']) ? json_encode($existing['seo'], JSON_THROW_ON_ERROR) : null);

        // Merge data fields
        $existingData = (array) ($existing['data'] ?? []);
        [$columnValues, $newDataValues, $relationsToSync, $searchText] = $this->partitionFields(
            $collection,
            $input,
            $title
        );
        $mergedData = array_merge($existingData, $newDataValues);

        $sql = "UPDATE `{$tableName}` SET
            `slug` = ?, `title` = ?, `status` = ?, `parent_id` = ?, `position` = ?, `author_id` = ?,
            `published_at` = ?, `scheduled_at` = ?, `seo` = ?, `data` = ?, `search_text` = ?,
            `updated_at` = ?, `updated_by` = ?
        WHERE `id` = ?";

        $this->connection->execute($sql, [
            $slug,
            $title,
            $newStatus,
            $parentId,
            $position,
            $authorId,
            $publishedAt,
            $scheduledAt,
            $seo,
            json_encode($mergedData, JSON_THROW_ON_ERROR),
            $searchText,
            $now,
            $updatedBy,
            $id,
        ]);

        // Sync relations
        foreach ($relationsToSync as $rel) {
            $this->relationManager->sync(
                $collectionId,
                $id,
                $rel['field_key'],
                $rel['target_collection_id'],
                $rel['target_entry_ids']
            );
        }

        // Record version
        $snapshot = array_merge($existing, $input, [
            'id' => $id,
            'slug' => $slug,
            'title' => $title,
            'status' => $newStatus,
            'parent_id' => $parentId,
            'position' => $position,
            'author_id' => $authorId,
            'published_at' => $publishedAt,
            'scheduled_at' => $scheduledAt,
            'data' => $mergedData,
        ]);

        $this->versionManager->recordVersion(
            $collectionId,
            $id,
            $newStatus,
            $snapshot,
            $changeSummary ?? 'Entry updated',
            $actorType,
            $actorLabel,
            $updatedBy,
            $isAutosave
        );

        return $this->find($collectionHandle, $id) ?? [];
    }

    /**
     * Soft delete (move to trash) or permanent purge.
     */
    public function delete(string $collectionHandle, string $id, bool $permanent = false): void
    {
        $collection = $this->collectionRegistry->get($collectionHandle);
        $collectionId = (string) $collection['id'];
        $prefix = $this->connection->getPrefix();
        $tableName = $prefix . 'c_' . $collectionHandle;
        $now = $this->clock !== null ? $this->clock->now()->format('Y-m-d H:i:s.v') : date('Y-m-d H:i:s.v');

        $existing = $this->find($collectionHandle, $id, true);
        if (!$existing) {
            throw new RuntimeException("Entry '{$id}' not found.");
        }

        if ($permanent) {
            // Delete relations
            $this->relationManager->purgeEntry($collectionId, $id);
            // Delete versions
            $this->connection->execute(
                "DELETE FROM `{$prefix}entry_versions` WHERE `collection_id` = ? AND `entry_id` = ?",
                [$collectionId, $id]
            );
            // Purge row
            $this->connection->execute("DELETE FROM `{$tableName}` WHERE `id` = ?", [$id]);
        } else {
            // Soft delete: append suffix to slug to release original slug for reuse
            $deletedSlug = $existing['slug'] . '-deleted-' . substr($id, -6);
            $this->connection->execute(
                "UPDATE `{$tableName}` SET `slug` = ?, `deleted_at` = ? WHERE `id` = ?",
                [$deletedSlug, $now, $id]
            );
        }
    }

    /**
     * Restore a soft-deleted entry from trash.
     *
     * @return array<string, mixed>
     */
    public function restore(string $collectionHandle, string $id): array
    {
        $prefix = $this->connection->getPrefix();
        $tableName = $prefix . 'c_' . $collectionHandle;

        $existing = $this->find($collectionHandle, $id, true);
        if (!$existing) {
            throw new RuntimeException("Entry '{$id}' not found.");
        }

        // Restore original slug if possible, or generate a fresh unique slug
        $rawSlug = preg_replace('/-deleted-[0-9A-Za-z]{6}$/', '', (string) $existing['slug']) ?? (string) $existing['title'];
        $restoredSlug = $this->slugService->generateUniqueSlug($collectionHandle, $rawSlug, (string) $existing['locale'], $id);

        $this->connection->execute(
            "UPDATE `{$tableName}` SET `slug` = ?, `deleted_at` = NULL WHERE `id` = ?",
            [$restoredSlug, $id]
        );

        return $this->find($collectionHandle, $id) ?? [];
    }

    /**
     * Duplicate an entry.
     *
     * @return array<string, mixed>
     */
    public function duplicate(
        string $collectionHandle,
        string $id,
        string $actorType = 'user',
        ?string $actorLabel = null,
        ?string $createdBy = null
    ): array {
        $source = $this->find($collectionHandle, $id);
        if (!$source) {
            throw new RuntimeException("Source entry '{$id}' not found for duplication.");
        }

        $input = $source['data'] ?? [];
        $titleField = $this->collectionRegistry->get($collectionHandle)['title_field'] ?? 'title';
        $input[$titleField] = $source['title'] . ' (copy)';
        $input['status'] = self::STATUS_DRAFT;
        $input['locale'] = $source['locale'];
        $input['parent_id'] = $source['parent_id'];
        $input['position'] = $source['position'];
        $input['seo'] = $source['seo'] ?? null;

        return $this->create($collectionHandle, $input, $actorType, $actorLabel, $createdBy);
    }

    /**
     * Find single entry by ID.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $collectionHandle, string $id, bool $includeTrashed = false): ?array
    {
        $collection = $this->collectionRegistry->get($collectionHandle);
        $collectionId = (string) $collection['id'];
        $prefix = $this->connection->getPrefix();
        $tableName = $prefix . 'c_' . $collectionHandle;

        $sql = "SELECT * FROM `{$tableName}` WHERE `id` = ?";
        if (!$includeTrashed) {
            $sql .= " AND `deleted_at` IS NULL";
        }

        $stmt = $this->connection->execute($sql, [$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        // Attach latest version number
        $vStmt = $this->connection->execute(
            "SELECT MAX(`version_no`) AS v_no FROM `{$prefix}entry_versions` WHERE `collection_id` = ? AND `entry_id` = ?",
            [$collectionId, $id]
        );
        $vRow = $vStmt->fetch();
        $version = ($vRow && $vRow['v_no'] !== null) ? (int) $vRow['v_no'] : 1;

        if (is_string($row['data'])) {
            $row['data'] = json_decode($row['data'], true) ?? [];
        }
        if (is_string($row['seo'])) {
            $row['seo'] = json_decode($row['seo'], true);
        }
        $row['version'] = $version;

        return $row;
    }

    /**
     * Find single entry by slug and locale.
     *
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $collectionHandle, string $slug, string $locale = 'en'): ?array
    {
        $prefix = $this->connection->getPrefix();
        $tableName = $prefix . 'c_' . $collectionHandle;

        $stmt = $this->connection->execute(
            "SELECT `id` FROM `{$tableName}` WHERE `slug` = ? AND `locale` = ? AND `deleted_at` IS NULL",
            [$slug, $locale]
        );
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        return $this->find($collectionHandle, (string) $row['id']);
    }

    /**
     * Query entries in a collection with filters, sorting, search, pagination, and populates.
     *
     * @return array{
     *     data: list<array<string, mixed>>,
     *     meta: array{
     *         pagination: array{
     *             total: int,
     *             page: int,
     *             size: int,
     *             pages: int,
     *             has_more: bool,
     *             next_cursor: ?string
     *         }
     *     }
     * }
     */
    public function query(
        string $collectionHandle,
        \BsCms\Content\Query\QueryOptions $options,
        ?string $actorId = null,
        bool $ownerOnly = false
    ): array {
        $collection = $this->collectionRegistry->get($collectionHandle);
        $collectionId = (string) $collection['id'];
        $prefix = $this->connection->getPrefix();
        $tableName = $prefix . 'c_' . $collectionHandle;

        $whereClauses = [];
        $bindings = [];

        // 1. Soft delete filter
        if (!$options->includeTrashed) {
            $whereClauses[] = "`deleted_at` IS NULL";
        }

        // 2. Owner-only filter (e.g. author role or owner self condition)
        if ($ownerOnly && $actorId !== null) {
            $whereClauses[] = "`author_id` = ?";
            $bindings[] = $actorId;
        }

        // 3. Search query
        if ($options->search !== null && $options->search !== '') {
            $whereClauses[] = "(`title` LIKE ? OR `search_text` LIKE ?)";
            $bindings[] = '%' . $options->search . '%';
            $bindings[] = '%' . $options->search . '%';
        }

        // 4. Dynamic filters
        foreach ($options->filters as $filter) {
            $colName = "`{$filter->field}`";
            $op = $filter->operator;
            $val = $filter->value;

            switch ($op) {
                case \BsCms\Content\Query\FilterClause::OP_EQ:
                    $whereClauses[] = "{$colName} = ?";
                    $bindings[] = $val;
                    break;
                case \BsCms\Content\Query\FilterClause::OP_NE:
                    $whereClauses[] = "{$colName} != ?";
                    $bindings[] = $val;
                    break;
                case \BsCms\Content\Query\FilterClause::OP_GT:
                    $whereClauses[] = "{$colName} > ?";
                    $bindings[] = $val;
                    break;
                case \BsCms\Content\Query\FilterClause::OP_GTE:
                    $whereClauses[] = "{$colName} >= ?";
                    $bindings[] = $val;
                    break;
                case \BsCms\Content\Query\FilterClause::OP_LT:
                    $whereClauses[] = "{$colName} < ?";
                    $bindings[] = $val;
                    break;
                case \BsCms\Content\Query\FilterClause::OP_LTE:
                    $whereClauses[] = "{$colName} <= ?";
                    $bindings[] = $val;
                    break;
                case \BsCms\Content\Query\FilterClause::OP_LIKE:
                    $whereClauses[] = "{$colName} LIKE ?";
                    $bindings[] = '%' . (string) $val . '%';
                    break;
                case \BsCms\Content\Query\FilterClause::OP_NULL:
                    $whereClauses[] = "{$colName} IS NULL";
                    break;
                case \BsCms\Content\Query\FilterClause::OP_NNULL:
                    $whereClauses[] = "{$colName} IS NOT NULL";
                    break;
                case \BsCms\Content\Query\FilterClause::OP_IN:
                    $inVals = is_array($val) ? array_values($val) : explode(',', (string) $val);
                    if (empty($inVals)) {
                        $whereClauses[] = '0=1';
                    } else {
                        $placeholders = implode(',', array_fill(0, count($inVals), '?'));
                        $whereClauses[] = "{$colName} IN ({$placeholders})";
                        foreach ($inVals as $iv) {
                            $bindings[] = $iv;
                        }
                    }
                    break;
                case \BsCms\Content\Query\FilterClause::OP_NIN:
                    $inVals = is_array($val) ? array_values($val) : explode(',', (string) $val);
                    if (!empty($inVals)) {
                        $placeholders = implode(',', array_fill(0, count($inVals), '?'));
                        $whereClauses[] = "{$colName} NOT IN ({$placeholders})";
                        foreach ($inVals as $iv) {
                            $bindings[] = $iv;
                        }
                    }
                    break;
            }
        }

        $whereSql = !empty($whereClauses) ? ' WHERE ' . implode(' AND ', $whereClauses) : '';

        // Count total for offset pagination
        $total = 0;
        if ($options->cursor === null) {
            $countStmt = $this->connection->execute("SELECT COUNT(*) AS cnt FROM `{$tableName}`{$whereSql}", $bindings);
            $countRow = $countStmt->fetch();
            $total = (int) ($countRow['cnt'] ?? 0);
        }

        // Build ORDER BY
        $orderClauses = [];
        $hasIdSort = false;
        foreach ($options->sort as $sort) {
            $dir = strtoupper($sort['direction']);
            $orderClauses[] = "`{$sort['field']}` {$dir}";
            if ($sort['field'] === 'id') {
                $hasIdSort = true;
            }
        }
        if (!$hasIdSort) {
            $orderClauses[] = "`id` ASC";
        }
        $orderSql = ' ORDER BY ' . implode(', ', $orderClauses);

        // Build LIMIT / OFFSET or Cursor
        $limit = $options->size;
        $page = $options->page;
        $offset = ($page - 1) * $limit;

        if ($options->cursor !== null) {
            $cursorWhere = !empty($whereClauses) ? "{$whereSql} AND `id` > ?" : " WHERE `id` > ?";
            $queryBindings = array_merge($bindings, [$options->cursor]);
            $sql = "SELECT * FROM `{$tableName}`{$cursorWhere}{$orderSql} LIMIT " . ($limit + 1);
            $stmt = $this->connection->execute($sql, $queryBindings);
        } else {
            $sql = "SELECT * FROM `{$tableName}`{$whereSql}{$orderSql} LIMIT {$limit} OFFSET {$offset}";
            $stmt = $this->connection->execute($sql, $bindings);
        }

        $rows = $stmt->fetchAll();
        $hasMore = false;
        $nextCursor = null;

        if ($options->cursor !== null && count($rows) > $limit) {
            $hasMore = true;
            array_pop($rows);
            $lastItem = end($rows);
            $nextCursor = is_array($lastItem) ? (string) ($lastItem['id'] ?? '') : null;
        }

        // Process rows and decode data / seo
        $entries = [];
        foreach ($rows as $row) {
            if (is_string($row['data'])) {
                $row['data'] = json_decode($row['data'], true) ?? [];
            }
            if (is_string($row['seo'])) {
                $row['seo'] = json_decode($row['seo'], true);
            }
            $entries[] = $row;
        }

        // Populates (batch query relations)
        if (!empty($options->populate) && !empty($entries)) {
            $entries = $this->populateRelations($collection, $entries, $options->populate);
        }

        // Sparse fieldsets
        if ($options->fields !== null) {
            $entries = $this->applySparseFields($entries, $options->fields);
        }

        $pages = $limit > 0 ? (int) ceil($total / $limit) : 1;
        if ($options->cursor === null) {
            $hasMore = $page < $pages;
            $lastItem = end($entries);
            $nextCursor = ($hasMore && is_array($lastItem)) ? (string) ($lastItem['id'] ?? '') : null;
        }

        return [
            'data' => $entries,
            'meta' => [
                'pagination' => [
                    'total' => $total,
                    'page' => $page,
                    'size' => $limit,
                    'pages' => $pages,
                    'has_more' => $hasMore,
                    'next_cursor' => $nextCursor,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $collection
     * @param list<array<string, mixed>> $entries
     * @param list<string> $populateFields
     * @return list<array<string, mixed>>
     */
    private function populateRelations(array $collection, array $entries, array $populateFields): array
    {
        $collectionId = (string) $collection['id'];
        $prefix = $this->connection->getPrefix();
        $entryIds = array_column($entries, 'id');

        if (empty($entryIds)) {
            return $entries;
        }

        $fields = (array) ($collection['fields'] ?? []);

        foreach ($populateFields as $relHandle) {
            $fieldDef = $fields[$relHandle] ?? null;
            if (!$fieldDef || ($fieldDef['type'] ?? '') !== 'relation') {
                continue;
            }

            $targetCollection = (string) ($fieldDef['config']['target_collection'] ?? '');
            if (!$this->collectionRegistry->has($targetCollection)) {
                continue;
            }

            $targetTableName = $prefix . 'c_' . $targetCollection;

            // Query relations in batch
            $inPlaceholders = implode(',', array_fill(0, count($entryIds), '?'));
            $relSql = "SELECT `from_entry_id` AS `entry_id`, `to_entry_id` AS `target_entry_id`, `position` FROM `{$prefix}entry_relations` WHERE `from_collection_id` = ? AND `field_key` = ? AND `from_entry_id` IN ({$inPlaceholders}) ORDER BY `position` ASC";
            $params = array_merge([$collectionId, $relHandle], $entryIds);
            $relStmt = $this->connection->execute($relSql, $params);
            $relRows = $relStmt->fetchAll();

            $targetIds = array_unique(array_filter(array_column($relRows, 'target_entry_id')));
            $targetsById = [];

            if (!empty($targetIds)) {
                $targetPlaceholders = implode(',', array_fill(0, count($targetIds), '?'));
                $targetSql = "SELECT * FROM `{$targetTableName}` WHERE `id` IN ({$targetPlaceholders}) AND `deleted_at` IS NULL";
                $targetStmt = $this->connection->execute($targetSql, array_values($targetIds));
                while ($tRow = $targetStmt->fetch()) {
                    if (is_string($tRow['data'])) {
                        $tRow['data'] = json_decode($tRow['data'], true) ?? [];
                    }
                    if (is_string($tRow['seo'])) {
                        $tRow['seo'] = json_decode($tRow['seo'], true);
                    }
                    $targetsById[(string) $tRow['id']] = $tRow;
                }
            }

            $relationsByEntryId = [];
            foreach ($relRows as $rRow) {
                $eId = (string) $rRow['entry_id'];
                $tId = (string) $rRow['target_entry_id'];
                if (isset($targetsById[$tId])) {
                    $relationsByEntryId[$eId][] = $targetsById[$tId];
                }
            }

            $cardinality = (string) ($fieldDef['config']['cardinality'] ?? 'many_to_many');
            foreach ($entries as &$entry) {
                $eId = (string) $entry['id'];
                $populatedItems = $relationsByEntryId[$eId] ?? [];
                if ($cardinality === 'one_to_one') {
                    $entry['data'][$relHandle] = $populatedItems[0] ?? null;
                } else {
                    $entry['data'][$relHandle] = $populatedItems;
                }
            }
            unset($entry);
        }

        return $entries;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param list<string> $requestedFields
     * @return list<array<string, mixed>>
     */
    private function applySparseFields(array $entries, array $requestedFields): array
    {
        $systemAlwaysInclude = ['id', 'status', 'locale'];
        $filtered = [];

        foreach ($entries as $entry) {
            $item = [];
            foreach ($systemAlwaysInclude as $sys) {
                if (isset($entry[$sys])) {
                    $item[$sys] = $entry[$sys];
                }
            }

            foreach ($requestedFields as $req) {
                if (isset($entry[$req])) {
                    $item[$req] = $entry[$req];
                } elseif (isset($entry['data'][$req])) {
                    $item[$req] = $entry['data'][$req];
                }
            }

            $filtered[] = $item;
        }

        return $filtered;
    }

    /**
     * Validate status transition rules.
     *
     * @param array<string, mixed> $input
     */
    private function validateStatusTransition(?string $oldStatus, string $newStatus, array $input): void
    {
        $allowed = [
            self::STATUS_DRAFT,
            self::STATUS_IN_REVIEW,
            self::STATUS_SCHEDULED,
            self::STATUS_PUBLISHED,
            self::STATUS_ARCHIVED,
        ];

        if (!in_array($newStatus, $allowed, true)) {
            throw new InvalidArgumentException("Invalid entry status '{$newStatus}'.");
        }

        if ($newStatus === self::STATUS_SCHEDULED) {
            $scheduledAt = $input['scheduled_at'] ?? null;
            if ($scheduledAt === null || trim((string) $scheduledAt) === '') {
                throw new InvalidArgumentException("Status 'scheduled' requires a future 'scheduled_at' timestamp.");
            }
            $ts = strtotime((string) $scheduledAt);
            $nowTs = $this->clock !== null ? $this->clock->now()->getTimestamp() : time();
            if ($ts <= $nowTs) {
                throw new InvalidArgumentException("Timestamp 'scheduled_at' must be in the future.");
            }
        }
    }

    /**
     * Partition input fields into column fields, JSON data fields, relations to sync, and search text.
     *
     * @param array<string, mixed> $collection
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: list<array<string, mixed>>, 3: string}
     */
    private function partitionFields(array $collection, array $input, string $title): array
    {
        $dataValues = [];
        $columnValues = [];
        $relationsToSync = [];
        $searchTerms = [$title];

        $fields = $collection['fields'] ?? [];

        foreach ($fields as $handle => $def) {
            $fHandle = (string) $handle;
            $fTypeStr = (string) ($def['type'] ?? 'text');
            $fType = $this->fieldTypeRegistry->get($fTypeStr);
            $config = (array) ($def['config'] ?? []);

            if (!array_key_exists($fHandle, $input)) {
                continue;
            }

            $rawVal = $input[$fHandle];
            $normalizedVal = $fType->normalize($rawVal, $config);

            if ($fTypeStr === 'relation') {
                $targetCollection = (string) ($config['target_collection'] ?? '');
                $targetColId = $this->collectionRegistry->has($targetCollection)
                    ? (string) $this->collectionRegistry->get($targetCollection)['id']
                    : $targetCollection;

                $targetIds = is_array($normalizedVal) ? $normalizedVal : ($normalizedVal !== null ? [$normalizedVal] : []);
                $relationsToSync[] = [
                    'field_key' => $fHandle,
                    'target_collection_id' => $targetColId,
                    'target_entry_ids' => $targetIds,
                ];
            } else {
                $dataValues[$fHandle] = $normalizedVal;

                if (!empty($def['is_searchable']) && is_scalar($normalizedVal)) {
                    $searchTerms[] = (string) $normalizedVal;
                }
            }
        }

        $searchText = implode(' ', array_filter($searchTerms));
        return [$columnValues, $dataValues, $relationsToSync, $searchText];
    }
}
