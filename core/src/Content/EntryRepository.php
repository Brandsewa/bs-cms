<?php

declare(strict_types=1);

namespace BsCms\Content;

use BsCms\Database\Connection;
use BsCms\Database\QueryBuilder;
use BsCms\Operations\Exceptions\ValidationException;
use BsCms\Redirects\RedirectRepository;
use BsCms\Schema\CollectionRegistry;
use BsCms\Schema\FieldTypeRegistry;
use BsCms\Schema\FieldTypes\ValidationContext;
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
        private readonly ?Clock $clock = null,
        private readonly ?RedirectRepository $redirectRepository = null
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

        // Apply field defaults defined in schema
        $fields = (array) ($collection['fields'] ?? []);
        foreach ($fields as $fHandle => $fDef) {
            if ((!array_key_exists($fHandle, $input) || $input[$fHandle] === null) && isset($fDef['default_value'])) {
                $input[$fHandle] = $fDef['default_value'];
            }
        }

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

        // Process fields into column vs data JSON and validate
        [$columnValues, $dataValues, $relationsToSync, $searchText] = $this->partitionFields(
            $collection,
            $input,
            $title,
            null,
            false
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
            self::serializeDataJson($dataValues),
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

        // Sync taxonomy term relationships
        $this->syncTaxonomyTerms($collection, $id, $dataValues, $status, $publishedAt, $now);

        // Sync media usage
        $this->syncMediaUsage($collection, $id, $dataValues);

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
        bool $isAutosave = false,
        bool $isPatch = true
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

        // Merge data fields and validate
        $existingData = (array) ($existing['data'] ?? []);
        [$columnValues, $newDataValues, $relationsToSync, $searchText] = $this->partitionFields(
            $collection,
            $input,
            $title,
            $id,
            true,
            $isPatch
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
            self::serializeDataJson($mergedData),
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

        // Sync taxonomy term relationships
        $this->syncTaxonomyTerms($collection, $id, $mergedData, $newStatus, $publishedAt, $now);

        // Sync media usage
        $this->syncMediaUsage($collection, $id, $mergedData);

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

        // Auto-redirect if slug changed on a published entry
        $oldSlug = (string) $existing['slug'];
        if ($slug !== $oldSlug && ($oldStatus === self::STATUS_PUBLISHED || $newStatus === self::STATUS_PUBLISHED || !empty($existing['published_at']))) {
            $this->createEntrySlugRedirect($collection, $collectionHandle, $oldSlug, $slug, $updatedBy);
        }

        return $this->find($collectionHandle, $id) ?? [];
    }

    /**
     * Create auto-redirect when a published entry slug changes.
     *
     * @param array<string, mixed> $collection
     */
    private function createEntrySlugRedirect(
        array $collection,
        string $collectionHandle,
        string $oldSlug,
        string $newSlug,
        ?string $actorId = null
    ): void {
        if ($oldSlug === $newSlug || $oldSlug === '') {
            return;
        }

        $redirectRepo = $this->redirectRepository ?? new RedirectRepository($this->connection);
        $routePattern = !empty($collection['route_pattern']) ? (string) $collection['route_pattern'] : null;

        if ($routePattern !== null && str_contains($routePattern, '{slug}')) {
            $fromPath = str_replace('{slug}', $oldSlug, $routePattern);
            $toPath = str_replace('{slug}', $newSlug, $routePattern);
            $redirectRepo->createAutoRedirect($fromPath, $toPath, $actorId);

            if ($collectionHandle === 'pages' && !str_starts_with($routePattern, '/pages/')) {
                $redirectRepo->createAutoRedirect('/pages/' . $oldSlug, '/pages/' . $newSlug, $actorId);
            }
        } else {
            // Default collection path: /{collectionHandle}/{slug}
            $fromPath = '/' . trim($collectionHandle, '/') . '/' . $oldSlug;
            $toPath = '/' . trim($collectionHandle, '/') . '/' . $newSlug;
            $redirectRepo->createAutoRedirect($fromPath, $toPath, $actorId);

            // If pages collection or public root pages
            if ($collectionHandle === 'pages') {
                $fromRoot = '/' . $oldSlug;
                $toRoot = '/' . $newSlug;
                $redirectRepo->createAutoRedirect($fromRoot, $toRoot, $actorId);
            }
        }
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
            // Delete taxonomy relationships
            $this->purgeTaxonomyTerms($id);
            // Delete media usage
            $this->purgeMediaUsage($id);
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
            // Unpublish term relationships so entry_count decreases
            $this->updateTaxonomyStatus($id, 0, null);
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

        $isPublished = ($existing['status'] === self::STATUS_PUBLISHED) ? 1 : 0;
        $publishedAt = $isPublished ? (string) ($existing['published_at'] ?? '') : null;
        $this->updateTaxonomyStatus($id, $isPublished, $publishedAt);

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
        if (!$this->collectionRegistry->has($collectionHandle)) {
            return null;
        }
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
        if ($options->trashed === 'only') {
            $whereClauses[] = "`deleted_at` IS NOT NULL";
        } elseif ($options->trashed === 'with' || $options->includeTrashed) {
            // Include both trashed and non-trashed: no deleted_at clause
        } else {
            // Default: exclude trashed entries
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
        $collectionFields = (array) ($collection['fields'] ?? []);
        $termRelTable = $this->connection->prefixTable('term_relationships');
        $termsTable = $this->connection->prefixTable('terms');

        foreach ($options->filters as $filter) {
            $fieldKey = $filter->field;
            $fDef = $collectionFields[$fieldKey] ?? null;
            $isTaxonomyField = is_array($fDef) && ($fDef['type'] ?? '') === 'taxonomy';

            if ($isTaxonomyField) {
                // Taxonomy filtering via term_relationships
                $op = $filter->operator;
                $val = $filter->value;
                $rawVals = is_array($val) ? array_values($val) : explode(',', (string) $val);
                $rawVals = array_filter(array_map('trim', $rawVals));

                if (empty($rawVals)) {
                    continue;
                }

                // Resolve term IDs (support ULIDs or slugs)
                $resolvedTermIds = [];
                $paths = [];
                foreach ($rawVals as $rv) {
                    $tStmt = $this->connection->execute(
                        "SELECT `id`, `path` FROM `{$termsTable}` WHERE `id` = ? OR `slug` = ?",
                        [$rv, $rv]
                    );
                    $tRow = $tStmt->fetch();
                    if ($tRow) {
                        $resolvedTermIds[] = (string) $tRow['id'];
                        $paths[] = (string) $tRow['path'];
                    }
                }

                if ($op === \BsCms\Content\Query\FilterClause::OP_ALL) {
                    // Match entries that have ALL specified terms
                    if (empty($resolvedTermIds)) {
                        $whereClauses[] = '0=1';
                    } else {
                        foreach ($resolvedTermIds as $tid) {
                            $whereClauses[] = "`id` IN (SELECT `entry_id` FROM `{$termRelTable}` WHERE `collection_id` = ? AND `term_id` = ?)";
                            $bindings[] = $collectionId;
                            $bindings[] = $tid;
                        }
                    }
                } else {
                    // in or eq
                    if (empty($resolvedTermIds)) {
                        $whereClauses[] = '0=1';
                    } else {
                        if ($options->includeDescendants && !empty($paths)) {
                            // Expand to include descendant term IDs
                            $subClauses = [];
                            $subBindings = [];
                            foreach ($paths as $p) {
                                $subClauses[] = "`path` LIKE ?";
                                $subBindings[] = \BsCms\Taxonomy\TermRepository::escapeLike($p) . '%';
                            }
                            $descStmt = $this->connection->execute(
                                "SELECT `id` FROM `{$termsTable}` WHERE " . implode(' OR ', $subClauses),
                                $subBindings
                            );
                            /** @var list<string> $allIds */
                            $allIds = $descStmt->fetchAll(\PDO::FETCH_COLUMN);
                            if (!empty($allIds)) {
                                $resolvedTermIds = array_values(array_unique(array_merge($resolvedTermIds, $allIds)));
                            }
                        }

                        $placeholders = implode(',', array_fill(0, count($resolvedTermIds), '?'));
                        $whereClauses[] = "`id` IN (SELECT `entry_id` FROM `{$termRelTable}` WHERE `collection_id` = ? AND `term_id` IN ({$placeholders}))";
                        $bindings[] = $collectionId;
                        foreach ($resolvedTermIds as $tid) {
                            $bindings[] = $tid;
                        }
                    }
                }
                continue;
            }

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
     * Serialize data array to JSON object string (guaranteeing '{}' instead of '[]' when empty).
     *
     * @param array<string, mixed> $data
     */
    private static function serializeDataJson(array $data): string
    {
        if (empty($data)) {
            return '{}';
        }
        return json_encode((object) $data, JSON_THROW_ON_ERROR);
    }

    /**
     * Partition and validate input fields.
     * Rejects unknown fields with 422, validates required fields and field type constraints.
     *
     * @param array<string, mixed> $collection
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: list<array<string, mixed>>, 3: string}
     */
    private function partitionFields(
        array $collection,
        array $input,
        string $title,
        ?string $entryId = null,
        bool $isUpdate = false,
        bool $isPatch = false
    ): array {
        $dataValues = [];
        $columnValues = [];
        $relationsToSync = [];
        $searchTerms = [$title];

        $fields = (array) ($collection['fields'] ?? []);

        // System top-level columns allowed in payload
        $systemAllowed = [
            'id', 'slug', 'title', 'status', 'locale', 'parent_id', 'position',
            'author_id', 'published_at', 'scheduled_at', 'seo', 'data',
            'version', 'expected_version',
            '_change_summary', '_autosave', 'confirm_token',
        ];

        $validationErrors = [];

        // Check for unknown fields in input
        foreach ($input as $key => $val) {
            $keyStr = (string) $key;
            if (in_array($keyStr, $systemAllowed, true)) {
                continue;
            }
            if (str_ends_with($keyStr, '_markdown')) {
                $baseKey = substr($keyStr, 0, -9);
                if (isset($fields[$baseKey]) && ($fields[$baseKey]['type'] ?? '') === 'richtext') {
                    continue;
                }
            }
            if (!isset($fields[$keyStr])) {
                $validationErrors[$keyStr] = ["Unknown field '{$keyStr}' is not defined in collection '{$collection['handle']}'."];
            }
        }

        // Validate each defined field
        foreach ($fields as $handle => $def) {
            $fHandle = (string) $handle;
            $fTypeStr = (string) ($def['type'] ?? 'text');
            $fType = $this->fieldTypeRegistry->get($fTypeStr);
            $config = (array) ($def['config'] ?? []);
            $isRequired = !empty($def['required']) || !empty($def['is_required']);

            $hasInput = array_key_exists($fHandle, $input);
            $rawVal = $hasInput ? $input[$fHandle] : null;

            if ($fTypeStr === 'richtext') {
                $mdKey = $fHandle . '_markdown';
                if (!$hasInput && array_key_exists($mdKey, $input)) {
                    $hasInput = true;
                    $rawVal = $input[$mdKey];
                }
            }

            // On create or full update (PUT): missing or empty required field is an error
            if ((!$isUpdate || !$isPatch) && $isRequired && (!$hasInput || $rawVal === null || $rawVal === '')) {
                $validationErrors[$fHandle] = ["Field '{$fHandle}' is required."];
                continue;
            }

            // On PATCH: if field is explicitly sent and set to null/empty when required, it is an error
            if ($isUpdate && $isPatch && $isRequired && $hasInput && ($rawVal === null || $rawVal === '')) {
                $validationErrors[$fHandle] = ["Field '{$fHandle}' is required."];
                continue;
            }

            if (!$hasInput) {
                continue;
            }

            // Field-type validation
            $vContext = new ValidationContext($input, $def, $collection, $entryId, $isUpdate);
            $errs = $fType->validate($rawVal, $config, $vContext);
            if (!empty($errs)) {
                $validationErrors[$fHandle] = $errs;
                continue;
            }

            // Fallback blocks validation if field handle is 'blocks' or type is blocks/json with blocks structure
            if (($fHandle === 'blocks' || $fTypeStr === 'blocks') && $hasInput && $rawVal !== null) {
                $blocksType = new \BsCms\Schema\FieldTypes\BlocksType();
                $blockErrs = $blocksType->validate($rawVal, $config, $vContext);
                if (!empty($blockErrs)) {
                    $validationErrors[$fHandle] = array_merge($validationErrors[$fHandle] ?? [], $blockErrs);
                    continue;
                }
            }

            // Normalize value
            $normalizedVal = $fType->normalize($rawVal, $config);

            if ($fTypeStr === 'media' && $normalizedVal !== null) {
                $checkIds = is_array($normalizedVal) ? $normalizedVal : [$normalizedVal];
                $mediaTable = $this->connection->prefixTable('media');
                foreach ($checkIds as $mId) {
                    $mStmt = $this->connection->execute(
                        "SELECT `id` FROM `{$mediaTable}` WHERE `id` = ? AND `deleted_at` IS NULL",
                        [$mId]
                    );
                    if ($mStmt->fetch() === false) {
                        $validationErrors[$fHandle][] = "Referenced media item '{$mId}' does not exist.";
                    }
                }
            }

            if ($fTypeStr === 'relation') {
                $targetCollection = (string) ($config['target_collection'] ?? '');
                $targetColId = $this->collectionRegistry->has($targetCollection)
                    ? (string) $this->collectionRegistry->get($targetCollection)['id']
                    : $targetCollection;

                $targetIds = is_array($normalizedVal) ? $normalizedVal : ($normalizedVal !== null ? [$normalizedVal] : []);
                if (!empty($targetIds) && $this->collectionRegistry->has($targetCollection)) {
                    $targetTableName = $this->connection->prefixTable('c_' . $targetCollection);
                    foreach ($targetIds as $tId) {
                        $tStmt = $this->connection->execute(
                            "SELECT `id` FROM `{$targetTableName}` WHERE `id` = ? AND `deleted_at` IS NULL",
                            [$tId]
                        );
                        if ($tStmt->fetch() === false) {
                            $validationErrors[$fHandle][] = "Referenced relation entry '{$tId}' does not exist in collection '{$targetCollection}'.";
                        }
                    }
                }

                $relationsToSync[] = [
                    'field_key' => $fHandle,
                    'target_collection_id' => $targetColId,
                    'target_entry_ids' => $targetIds,
                ];
            } else {
                $dataValues[$fHandle] = $normalizedVal;

                if ($fTypeStr === 'richtext' && is_array($normalizedVal)) {
                    $derived = \BsCms\Document\DerivedFields::extract($normalizedVal);
                    $lastDerived = $derived;
                    if (!empty($derived['search_text'])) {
                        $searchTerms[] = $derived['search_text'];
                    }

                    // Validate existence of any embedded media IDs
                    if (!empty($derived['media_ids'])) {
                        $mediaTable = $this->connection->prefixTable('media');
                        foreach ($derived['media_ids'] as $mId) {
                            $mStmt = $this->connection->execute(
                                "SELECT `id` FROM `{$mediaTable}` WHERE `id` = ? AND `deleted_at` IS NULL",
                                [$mId]
                            );
                            if ($mStmt->fetch() === false) {
                                $validationErrors[$fHandle][] = "Document references nonexistent media '{$mId}'.";
                            }
                        }
                    }
                } elseif (!empty($def['is_searchable']) && is_scalar($normalizedVal)) {
                    $searchTerms[] = (string) $normalizedVal;
                }
            }
        }

        // Apply derived fields on save
        if (isset($lastDerived)) {
            if (isset($fields['excerpt']) && empty($dataValues['excerpt'])) {
                $dataValues['excerpt'] = $lastDerived['excerpt'];
            }
            if (isset($fields['reading_time'])) {
                $dataValues['reading_time'] = $lastDerived['reading_time'];
            }
            if (isset($fields['word_count'])) {
                $dataValues['word_count'] = $lastDerived['word_count'];
            }
            $dataValues['_derived'] = [
                'excerpt' => $lastDerived['excerpt'],
                'reading_time' => $lastDerived['reading_time'],
                'word_count' => $lastDerived['word_count'],
                'outline' => $lastDerived['outline'],
                'media_ids' => $lastDerived['media_ids'],
                'internal_links' => $lastDerived['internal_links'],
            ];
        } else {
            $joinedSearch = implode(' ', array_filter($searchTerms));
            if (!empty($joinedSearch)) {
                $wCount = str_word_count($joinedSearch);
                $rTime = $wCount > 0 ? (int) ceil($wCount / 200) : 0;
                if (isset($fields['reading_time']) && !isset($dataValues['reading_time'])) {
                    $dataValues['reading_time'] = $rTime;
                }
                if (isset($fields['excerpt']) && empty($dataValues['excerpt'])) {
                    $dataValues['excerpt'] = \BsCms\Document\DerivedFields::truncateExcerpt($joinedSearch, 160);
                }
            }
        }

        if (!empty($validationErrors)) {
            throw new ValidationException('Validation failed for entry.', $validationErrors);
        }

        $searchText = implode(' ', array_filter($searchTerms));
        return [$columnValues, $dataValues, $relationsToSync, $searchText];
    }

    /**
     * @param array<string, mixed> $collection
     * @param array<string, mixed> $dataValues
     */
    private function syncTaxonomyTerms(
        array $collection,
        string $entryId,
        array $dataValues,
        string $status,
        ?string $publishedAt,
        string $now
    ): void {
        $fields = (array) ($collection['fields'] ?? []);
        $taxonomyFields = [];
        foreach ($fields as $fKey => $fDef) {
            if (is_array($fDef) && ($fDef['type'] ?? '') === 'taxonomy') {
                $taxonomyFields[$fKey] = $fDef;
            }
        }

        if (empty($taxonomyFields)) {
            return;
        }

        $collectionId = (string) $collection['id'];
        $termRelTable = $this->connection->prefixTable('term_relationships');
        $termsTable = $this->connection->prefixTable('terms');

        $isPublished = ($status === self::STATUS_PUBLISHED) ? 1 : 0;
        $pubAt = $isPublished ? $publishedAt : null;

        // Collect existing term IDs for this entry to update their counts later
        $prevTermIdsStmt = $this->connection->execute(
            "SELECT `term_id` FROM `{$termRelTable}` WHERE `collection_id` = ? AND `entry_id` = ?",
            [$collectionId, $entryId]
        );
        /** @var list<string> $affectedTermIds */
        $affectedTermIds = $prevTermIdsStmt->fetchAll(\PDO::FETCH_COLUMN);

        // Delete existing relationships for this entry
        $this->connection->execute(
            "DELETE FROM `{$termRelTable}` WHERE `collection_id` = ? AND `entry_id` = ?",
            [$collectionId, $entryId]
        );

        foreach ($taxonomyFields as $fKey => $fDef) {
            if (!array_key_exists($fKey, $dataValues) || $dataValues[$fKey] === null) {
                continue;
            }

            $val = $dataValues[$fKey];
            $items = is_array($val) ? array_values($val) : [$val];
            $items = array_filter(array_map('trim', array_map('strval', $items)));

            if (empty($items)) {
                continue;
            }

            $order = 0;
            foreach ($items as $item) {
                // Look up term
                $tStmt = $this->connection->execute(
                    "SELECT `id`, `taxonomy_id` FROM `{$termsTable}` WHERE `id` = ? OR `slug` = ?",
                    [$item, $item]
                );
                $term = $tStmt->fetch();
                if ($term) {
                    $tid = (string) $term['id'];
                    $isPrimary = ($order === 0) ? 1 : 0;

                    $this->connection->execute(
                        "INSERT IGNORE INTO `{$termRelTable}` (
                            `collection_id`, `entry_id`, `term_id`,
                            `is_primary`, `position`, `is_published`, `sort_at`
                        ) VALUES (?, ?, ?, ?, ?, ?, ?)",
                        [$collectionId, $entryId, $tid, $isPrimary, $order++, $isPublished, $pubAt]
                    );

                    $affectedTermIds[] = $tid;
                }
            }
        }

        // Recompute entry_count for all affected terms
        $uniqueTermIds = array_values(array_unique($affectedTermIds));
        if (!empty($uniqueTermIds)) {
            $this->recomputeTermsCount($uniqueTermIds);
        }
    }

    private function updateTaxonomyStatus(string $entryId, int $isPublished, ?string $publishedAt): void
    {
        $termRelTable = $this->connection->prefixTable('term_relationships');
        $prevTermIdsStmt = $this->connection->execute(
            "SELECT `term_id` FROM `{$termRelTable}` WHERE `entry_id` = ?",
            [$entryId]
        );
        /** @var list<string> $affectedTermIds */
        $affectedTermIds = $prevTermIdsStmt->fetchAll(\PDO::FETCH_COLUMN);

        if (empty($affectedTermIds)) {
            return;
        }

        $this->connection->execute(
            "UPDATE `{$termRelTable}` SET `is_published` = ?, `sort_at` = ? WHERE `entry_id` = ?",
            [$isPublished, $publishedAt, $entryId]
        );

        $this->recomputeTermsCount(array_values(array_unique($affectedTermIds)));
    }

    private function purgeTaxonomyTerms(string $entryId): void
    {
        $termRelTable = $this->connection->prefixTable('term_relationships');
        $prevTermIdsStmt = $this->connection->execute(
            "SELECT `term_id` FROM `{$termRelTable}` WHERE `entry_id` = ?",
            [$entryId]
        );
        /** @var list<string> $affectedTermIds */
        $affectedTermIds = $prevTermIdsStmt->fetchAll(\PDO::FETCH_COLUMN);

        if (empty($affectedTermIds)) {
            return;
        }

        $this->connection->execute("DELETE FROM `{$termRelTable}` WHERE `entry_id` = ?", [$entryId]);
        $this->recomputeTermsCount(array_values(array_unique($affectedTermIds)));
    }

    /**
     * @param list<string> $termIds
     */
    private function recomputeTermsCount(array $termIds): void
    {
        if (empty($termIds)) {
            return;
        }
        $termsTable = $this->connection->prefixTable('terms');
        $termRelTable = $this->connection->prefixTable('term_relationships');
        $placeholders = implode(',', array_fill(0, count($termIds), '?'));

        $sql = "UPDATE `{$termsTable}` t
                SET `entry_count` = (
                    SELECT COUNT(DISTINCT r.entry_id)
                    FROM `{$termRelTable}` r
                    WHERE r.term_id = t.id AND r.is_published = 1
                )
                WHERE t.id IN ({$placeholders})";
        $this->connection->execute($sql, $termIds);
    }

    /**
     * Synchronize media usage tracking for an entry.
     *
     * @param array<string, mixed> $collection
     * @param array<string, mixed> $dataValues
     */
    private function syncMediaUsage(array $collection, string $entryId, array $dataValues): void
    {
        $fields = (array) ($collection['fields'] ?? []);
        $collectionId = (string) $collection['id'];
        $usageTable = $this->connection->prefixTable('media_usage');
        $mediaTable = $this->connection->prefixTable('media');

        // Extract media IDs from media fields and richtext fields
        /** @var list<array{field_key: string, media_id: string}> $newUsages */
        $newUsages = [];
        foreach ($fields as $fKey => $fDef) {
            if (!is_array($fDef) || !array_key_exists($fKey, $dataValues)) {
                continue;
            }

            $type = (string) ($fDef['type'] ?? '');
            $val = $dataValues[$fKey];

            if ($type === 'media') {
                if (is_string($val) && preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/i', trim($val))) {
                    $newUsages[] = ['field_key' => $fKey, 'media_id' => strtoupper(trim($val))];
                } elseif (is_array($val)) {
                    foreach ($val as $mId) {
                        if (is_string($mId) && preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/i', trim($mId))) {
                            $newUsages[] = ['field_key' => $fKey, 'media_id' => strtoupper(trim($mId))];
                        }
                    }
                }
            } elseif ($type === 'richtext') {
                $doc = $val;
                if (is_string($val)) {
                    $decoded = json_decode($val, true);
                    if (is_array($decoded)) {
                        $doc = $decoded;
                    }
                }
                if (is_array($doc)) {
                    $mediaIds = \BsCms\Document\DerivedFields::extractMediaIds($doc);
                    foreach ($mediaIds as $mId) {
                        $newUsages[] = ['field_key' => $fKey, 'media_id' => strtoupper(trim($mId))];
                    }
                }
            }
        }

        // Fetch previous usages to find affected media IDs
        $prevStmt = $this->connection->execute(
            "SELECT `media_id` FROM `{$usageTable}` WHERE `entry_id` = ?",
            [$entryId]
        );
        /** @var list<string> $prevMediaIds */
        $prevMediaIds = $prevStmt->fetchAll(\PDO::FETCH_COLUMN);

        // Delete existing usage for this entry
        $this->connection->execute("DELETE FROM `{$usageTable}` WHERE `entry_id` = ?", [$entryId]);

        $affectedMediaIds = $prevMediaIds;

        // Insert new usages
        foreach ($newUsages as $usage) {
            $this->connection->execute(
                "INSERT IGNORE INTO `{$usageTable}` (`media_id`, `collection_id`, `entry_id`, `field_key`)
                 VALUES (?, ?, ?, ?)",
                [$usage['media_id'], $collectionId, $entryId, $usage['field_key']]
            );
            $affectedMediaIds[] = $usage['media_id'];
        }

        // Recompute usage_count on media table
        $uniqueMediaIds = array_values(array_unique($affectedMediaIds));
        foreach ($uniqueMediaIds as $mId) {
            $this->connection->execute(
                "UPDATE `{$mediaTable}` SET `usage_count` = (SELECT COUNT(*) FROM `{$usageTable}` WHERE `media_id` = ?) WHERE `id` = ?",
                [$mId, $mId]
            );
        }
    }

    private function purgeMediaUsage(string $entryId): void
    {
        $usageTable = $this->connection->prefixTable('media_usage');
        $mediaTable = $this->connection->prefixTable('media');

        $prevStmt = $this->connection->execute(
            "SELECT `media_id` FROM `{$usageTable}` WHERE `entry_id` = ?",
            [$entryId]
        );
        /** @var list<string> $prevMediaIds */
        $prevMediaIds = $prevStmt->fetchAll(\PDO::FETCH_COLUMN);

        $this->connection->execute("DELETE FROM `{$usageTable}` WHERE `entry_id` = ?", [$entryId]);

        $uniqueMediaIds = array_values(array_unique($prevMediaIds));
        foreach ($uniqueMediaIds as $mId) {
            $this->connection->execute(
                "UPDATE `{$mediaTable}` SET `usage_count` = (SELECT COUNT(*) FROM `{$usageTable}` WHERE `media_id` = ?) WHERE `id` = ?",
                [$mId, $mId]
            );
        }
    }

    /**
     * Aggregate collection counts per status and trash in a single efficient query.
     *
     * @return array{all: int, published: int, draft: int, in_review: int, archived: int, trash: int}
     */
    public function getCounts(string $collectionHandle, ?string $actorId = null, bool $ownerOnly = false): array
    {
        $prefix = $this->connection->getPrefix();
        $tableName = $prefix . 'c_' . $collectionHandle;

        $userClause = '';
        $bindings = [];
        if ($ownerOnly && $actorId !== null) {
            $userClause = " AND `author_id` = ?";
            $bindings[] = $actorId;
        }

        // Single SQL query with conditional aggregations:
        // - all: non-deleted entries
        // - published: non-deleted published entries
        // - draft: non-deleted draft entries
        // - in_review: non-deleted in_review entries
        // - archived: non-deleted archived entries
        // - trash: soft-deleted entries (deleted_at IS NOT NULL)
        $sql = "SELECT
            COUNT(CASE WHEN `deleted_at` IS NULL THEN 1 END) AS `all_count`,
            COUNT(CASE WHEN `deleted_at` IS NULL AND `status` = 'published' THEN 1 END) AS `published_count`,
            COUNT(CASE WHEN `deleted_at` IS NULL AND `status` = 'draft' THEN 1 END) AS `draft_count`,
            COUNT(CASE WHEN `deleted_at` IS NULL AND `status` = 'in_review' THEN 1 END) AS `in_review_count`,
            COUNT(CASE WHEN `deleted_at` IS NULL AND `status` = 'archived' THEN 1 END) AS `archived_count`,
            COUNT(CASE WHEN `deleted_at` IS NOT NULL THEN 1 END) AS `trash_count`
            FROM `{$tableName}`
            WHERE 1=1{$userClause}";

        $stmt = $this->connection->execute($sql, $bindings);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return [
                'all' => 0,
                'published' => 0,
                'draft' => 0,
                'in_review' => 0,
                'archived' => 0,
                'trash' => 0,
            ];
        }

        return [
            'all' => (int) ($row['all_count'] ?? 0),
            'published' => (int) ($row['published_count'] ?? 0),
            'draft' => (int) ($row['draft_count'] ?? 0),
            'in_review' => (int) ($row['in_review_count'] ?? 0),
            'archived' => (int) ($row['archived_count'] ?? 0),
            'trash' => (int) ($row['trash_count'] ?? 0),
        ];
    }
}
