<?php

declare(strict_types=1);

namespace BsCms\Taxonomy;

use BsCms\Database\Connection;
use BsCms\Security\Ulid;
use BsCms\Support\Clock;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class TermRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Escape LIKE wildcard characters (_ and %) and backslash so dynamic values can be safely embedded in a LIKE prefix pattern.
     */
    public static function escapeLike(string $pattern): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $pattern);
    }

    /**
     * Acquire a named lock for taxonomy tree operations.
     */
    public function lockTaxonomy(string $taxonomyId, int $timeoutSeconds = 10): bool
    {
        $stmt = $this->connection->execute('SELECT GET_LOCK(?, ?)', ['bs_tax_' . $taxonomyId, $timeoutSeconds]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Release named lock.
     */
    public function releaseTaxonomy(string $taxonomyId): void
    {
        $this->connection->execute('SELECT RELEASE_LOCK(?)', ['bs_tax_' . $taxonomyId]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array
    {
        $table = $this->connection->prefixTable('terms');
        $stmt = $this->connection->execute("SELECT * FROM `{$table}` WHERE `id` = ?", [$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return $this->formatTerm($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $taxonomyId, string $slug): ?array
    {
        $table = $this->connection->prefixTable('terms');
        $stmt = $this->connection->execute("SELECT * FROM `{$table}` WHERE `taxonomy_id` = ? AND `slug` = ?", [$taxonomyId, $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return $this->formatTerm($row);
    }

    /**
     * Find by slug_path (e.g. 'news/community/events')
     *
     * @return array<string, mixed>|null
     */
    public function findBySlugPath(string $taxonomyId, string $slugPath): ?array
    {
        $table = $this->connection->prefixTable('terms');
        $stmt = $this->connection->execute("SELECT * FROM `{$table}` WHERE `taxonomy_id` = ? AND `slug_path` = ?", [$taxonomyId, $slugPath]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return $this->formatTerm($row);
    }

    /**
     * List terms in tree order (hierarchical, ordered by path and position).
     *
     * @return list<array<string, mixed>>
     */
    public function listTree(string $taxonomyId, ?string $parentId = null): array
    {
        $table = $this->connection->prefixTable('terms');

        if ($parentId === null) {
            $stmt = $this->connection->execute(
                "SELECT * FROM `{$table}` WHERE `taxonomy_id` = ? ORDER BY `path` ASC, `position` ASC",
                [$taxonomyId]
            );
        } else {
            $parent = $this->findById($parentId);
            if ($parent === null) {
                return [];
            }
            $escapedPath = self::escapeLike($parent['path']);
            $stmt = $this->connection->execute(
                "SELECT * FROM `{$table}` WHERE `taxonomy_id` = ? AND `path` LIKE ? ORDER BY `path` ASC, `position` ASC",
                [$taxonomyId, $escapedPath . '%']
            );
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map([$this, 'formatTerm'], $rows);
    }

    /**
     * Create term in a taxonomy.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(string $taxonomyId, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException("Term name is required.");
        }

        $slug = trim((string) ($data['slug'] ?? ''));
        if ($slug === '') {
            $slug = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9_-]+/', '-', $name), '-'));
        }
        if ($slug === '' || !preg_match('/^[a-z0-9_-]+$/', $slug)) {
            throw new InvalidArgumentException("Invalid term slug '{$slug}'.");
        }

        $parentId = isset($data['parent_id']) && $data['parent_id'] !== '' ? (string) $data['parent_id'] : null;

        // Check max depth if parent specified
        $taxTable = $this->connection->prefixTable('taxonomies');
        $taxStmt = $this->connection->execute("SELECT * FROM `{$taxTable}` WHERE `id` = ?", [$taxonomyId]);
        $taxonomy = $taxStmt->fetch(PDO::FETCH_ASSOC);
        if (!$taxonomy) {
            throw new InvalidArgumentException("Taxonomy '{$taxonomyId}' not found.");
        }

        if (empty($taxonomy['hierarchical']) && $parentId !== null) {
            throw new InvalidArgumentException("Flat taxonomy does not allow hierarchical parent_id.");
        }

        $depth = 0;
        $path = '';
        $slugPath = $slug;

        $id = Ulid::generate();

        if ($parentId !== null) {
            $parent = $this->findById($parentId);
            if ($parent === null || $parent['taxonomy_id'] !== $taxonomyId) {
                throw new InvalidArgumentException("Parent term '{$parentId}' not found in taxonomy.");
            }
            $depth = $parent['depth'] + 1;
            if ($taxonomy['max_depth'] !== null && $depth > (int) $taxonomy['max_depth']) {
                throw new InvalidArgumentException("Exceeded maximum depth of {$taxonomy['max_depth']}.");
            }
            $path = $parent['path'] . $id . '/';
            $slugPath = $parent['slug_path'] . '/' . $slug;
        } else {
            $path = '/' . $id . '/';
        }

        $position = isset($data['position']) ? (int) $data['position'] : 0;
        $now = Clock::sql(Clock::now());
        $table = $this->connection->prefixTable('terms');

        $parentKey = $parentId ?? '';

        $this->connection->execute(
            "INSERT INTO `{$table}` (
                `id`, `taxonomy_id`, `parent_id`, `parent_key`, `name`, `slug`, `description`,
                `path`, `slug_path`, `depth`, `position`, `image_media_id`, `color`,
                `icon`, `data`, `seo`, `entry_count`, `created_at`, `updated_at`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)",
            [
                $id,
                $taxonomyId,
                $parentId,
                $parentKey,
                $name,
                $slug,
                $data['description'] ?? null,
                $path,
                $slugPath,
                $depth,
                $position,
                $data['image_media_id'] ?? null,
                $data['color'] ?? null,
                $data['icon'] ?? null,
                isset($data['data']) ? json_encode($data['data']) : null,
                isset($data['seo']) ? json_encode($data['seo']) : null,
                $now,
                $now,
            ]
        );

        return $this->findById($id) ?? [];
    }

    /**
     * Update term fields. If slug changes, updates slug_path for term and all descendants.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(string $termId, array $data): array
    {
        $term = $this->findById($termId);
        if ($term === null) {
            throw new InvalidArgumentException("Term '{$termId}' not found.");
        }

        $taxonomyId = (string) $term['taxonomy_id'];
        $table = $this->connection->prefixTable('terms');
        $now = Clock::sql(Clock::now());

        $name = array_key_exists('name', $data) ? trim((string) $data['name']) : (string) $term['name'];
        if ($name === '') {
            throw new InvalidArgumentException("Term name cannot be empty.");
        }

        $slugChanged = false;
        $newSlug = (string) $term['slug'];
        if (array_key_exists('slug', $data)) {
            $candidateSlug = trim((string) $data['slug']);
            if ($candidateSlug !== '' && $candidateSlug !== $term['slug']) {
                if (!preg_match('/^[a-z0-9_-]+$/', $candidateSlug)) {
                    throw new InvalidArgumentException("Invalid term slug format: '{$candidateSlug}'.");
                }
                $newSlug = $candidateSlug;
                $slugChanged = true;
            }
        }

        $description = array_key_exists('description', $data) ? $data['description'] : $term['description'];
        $position = array_key_exists('position', $data) ? (int) $data['position'] : (int) $term['position'];
        $imageMediaId = array_key_exists('image_media_id', $data) ? $data['image_media_id'] : $term['image_media_id'];
        $color = array_key_exists('color', $data) ? $data['color'] : $term['color'];
        $icon = array_key_exists('icon', $data) ? $data['icon'] : $term['icon'];
        $termData = array_key_exists('data', $data) ? (is_array($data['data']) ? json_encode($data['data']) : $data['data']) : (isset($term['data']) ? json_encode($term['data']) : null);
        $seo = array_key_exists('seo', $data) ? (is_array($data['seo']) ? json_encode($data['seo']) : $data['seo']) : (isset($term['seo']) ? json_encode($term['seo']) : null);

        $parentChanged = false;
        $newParentId = $term['parent_id'];
        if (array_key_exists('parent_id', $data)) {
            $candidateParentId = isset($data['parent_id']) && $data['parent_id'] !== '' ? (string) $data['parent_id'] : null;
            if ($candidateParentId !== $term['parent_id']) {
                $newParentId = $candidateParentId;
                $parentChanged = true;
            }
        }

        if ($parentChanged) {
            // Re-parent using move() which properly updates path, depth, slug_path, and parent_key under lock
            $this->move($termId, $newParentId, $position);
        }

        if (!$slugChanged) {
            $this->connection->execute(
                "UPDATE `{$table}` SET
                    `name` = ?,
                    `description` = ?,
                    `position` = ?,
                    `image_media_id` = ?,
                    `color` = ?,
                    `icon` = ?,
                    `data` = ?,
                    `seo` = ?,
                    `updated_at` = ?
                 WHERE `id` = ?",
                [$name, $description, $position, $imageMediaId, $color, $icon, $termData, $seo, $now, $termId]
            );
            return $this->findById($termId) ?? [];
        }

        // Slug changed: acquire lock and update descendant slug_paths
        $locked = $this->lockTaxonomy($taxonomyId, 10);
        if (!$locked) {
            throw new RuntimeException("Could not acquire taxonomy lock for slug update.");
        }

        try {
            $oldSlugPath = (string) $term['slug_path'];
            if ($term['parent_id'] !== null) {
                $parent = $this->findById((string) $term['parent_id']);
                $newSlugPath = ($parent !== null ? (string) $parent['slug_path'] : '') . '/' . $newSlug;
            } else {
                $newSlugPath = $newSlug;
            }

            $this->connection->transaction(function () use ($table, $name, $newSlug, $newSlugPath, $description, $position, $imageMediaId, $color, $icon, $termData, $seo, $now, $termId, $taxonomyId, $oldSlugPath, $term) {
                $this->connection->execute(
                    "UPDATE `{$table}` SET
                        `name` = ?,
                        `slug` = ?,
                        `slug_path` = ?,
                        `description` = ?,
                        `position` = ?,
                        `image_media_id` = ?,
                        `color` = ?,
                        `icon` = ?,
                        `data` = ?,
                        `seo` = ?,
                        `updated_at` = ?
                     WHERE `id` = ?",
                    [$name, $newSlug, $newSlugPath, $description, $position, $imageMediaId, $color, $icon, $termData, $seo, $now, $termId]
                );

                // Update descendant slug paths
                $escapedPath = self::escapeLike((string) $term['path']);
                $descendantsStmt = $this->connection->execute(
                    "SELECT `id`, `slug_path` FROM `{$table}` WHERE `taxonomy_id` = ? AND `path` LIKE ? AND `id` != ?",
                    [$taxonomyId, $escapedPath . '%', $termId]
                );
                /** @var list<array<string, mixed>> $descendants */
                $descendants = $descendantsStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($descendants as $desc) {
                    $subOldSlugPath = (string) $desc['slug_path'];
                    $relSlugPath = substr($subOldSlugPath, strlen($oldSlugPath));
                    $updatedSubSlugPath = $newSlugPath . $relSlugPath;

                    $this->connection->execute(
                        "UPDATE `{$table}` SET `slug_path` = ?, `updated_at` = ? WHERE `id` = ?",
                        [$updatedSubSlugPath, $now, $desc['id']]
                    );
                }
            });

            return $this->findById($termId) ?? [];
        } finally {
            $this->releaseTaxonomy($taxonomyId);
        }
    }

    /**
     * Move term to new parent or reorder position.
     * Enforces GET_LOCK concurrency, cycle detection, and updates paths of entire subtree in one transaction.
     *
     * @return array<string, mixed>
     */
    public function move(string $termId, ?string $newParentId, ?int $newPosition = null): array
    {
        $term = $this->findById($termId);
        if ($term === null) {
            throw new InvalidArgumentException("Term '{$termId}' not found.");
        }

        $taxonomyId = (string) $term['taxonomy_id'];
        $locked = $this->lockTaxonomy($taxonomyId, 10);
        if (!$locked) {
            throw new RuntimeException("Could not acquire taxonomy lock for move operation.");
        }

        try {
            // Cannot move term to itself
            if ($newParentId === $termId) {
                throw new InvalidArgumentException("Cannot move term into itself.");
            }

            $newParent = null;
            // Cannot move term into its own descendants (cycle detection)
            if ($newParentId !== null) {
                $newParent = $this->findById($newParentId);
                if ($newParent === null || $newParent['taxonomy_id'] !== $taxonomyId) {
                    throw new InvalidArgumentException("Target parent '{$newParentId}' not found in taxonomy.");
                }
                // Check if newParent path starts with term's path
                if (str_starts_with((string) $newParent['path'], (string) $term['path'])) {
                    throw new InvalidArgumentException("Cannot move term into its own descendant.");
                }

                // Check max depth constraint
                $taxTable = $this->connection->prefixTable('taxonomies');
                $taxStmt = $this->connection->execute("SELECT `max_depth` FROM `{$taxTable}` WHERE `id` = ?", [$taxonomyId]);
                $maxDepth = $taxStmt->fetchColumn();
                $newDepth = ((int) $newParent['depth']) + 1;
                if ($maxDepth !== null && $maxDepth !== false && $newDepth > (int) $maxDepth) {
                    throw new InvalidArgumentException("Move would exceed maximum depth of {$maxDepth}.");
                }
            }

            $oldPath = (string) $term['path'];
            $oldSlugPath = (string) $term['slug_path'];

            if ($newParent !== null) {
                $newDepth = ((int) $newParent['depth']) + 1;
                $newPath = ((string) $newParent['path']) . $termId . '/';
                $newSlugPath = ((string) $newParent['slug_path']) . '/' . ((string) $term['slug']);
            } else {
                $newDepth = 0;
                $newPath = '/' . $termId . '/';
                $newSlugPath = (string) $term['slug'];
            }

            $now = Clock::sql(Clock::now());
            $position = $newPosition ?? (int) $term['position'];
            $table = $this->connection->prefixTable('terms');

            $parentKey = $newParentId ?? '';

            $this->connection->transaction(function () use ($table, $newParentId, $parentKey, $newDepth, $newPath, $newSlugPath, $position, $now, $termId, $term, $oldPath, $oldSlugPath, $taxonomyId) {
                // Update moved root node
                $this->connection->execute(
                    "UPDATE `{$table}` SET
                        `parent_id` = ?,
                        `parent_key` = ?,
                        `depth` = ?,
                        `path` = ?,
                        `slug_path` = ?,
                        `position` = ?,
                        `updated_at` = ?
                     WHERE `id` = ?",
                    [$newParentId, $parentKey, $newDepth, $newPath, $newSlugPath, $position, $now, $termId]
                );

                // Update all descendants in subtree
                $depthDiff = $newDepth - ((int) $term['depth']);
                $escapedOldPath = self::escapeLike($oldPath);
                $descendantsStmt = $this->connection->execute(
                    "SELECT `id`, `depth`, `path`, `slug_path` FROM `{$table}` WHERE `taxonomy_id` = ? AND `path` LIKE ? AND `id` != ?",
                    [$taxonomyId, $escapedOldPath . '%', $termId]
                );
                /** @var list<array<string, mixed>> $descendants */
                $descendants = $descendantsStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($descendants as $desc) {
                    $subOldPath = (string) $desc['path'];
                    $subOldSlugPath = (string) $desc['slug_path'];

                    $relPath = substr($subOldPath, strlen($oldPath));
                    $updatedSubPath = $newPath . $relPath;

                    $relSlugPath = substr($subOldSlugPath, strlen($oldSlugPath));
                    $updatedSubSlugPath = $newSlugPath . $relSlugPath;

                    $updatedDepth = ((int) $desc['depth']) + $depthDiff;

                    $this->connection->execute(
                        "UPDATE `{$table}` SET `depth` = ?, `path` = ?, `slug_path` = ?, `updated_at` = ? WHERE `id` = ?",
                        [$updatedDepth, $updatedSubPath, $updatedSubSlugPath, $now, $desc['id']]
                    );
                }
            });

            return $this->findById($termId) ?? [];
        } finally {
            $this->releaseTaxonomy($taxonomyId);
        }
    }

    /**
     * Delete a term. Blocks if children exist (unless children are deleted/moved first).
     * Option to reassign entry relationships to $reassignToTermId.
     */
    public function delete(string $termId, ?string $reassignToTermId = null): void
    {
        $term = $this->findById($termId);
        if ($term === null) {
            return;
        }

        $taxonomyId = (string) $term['taxonomy_id'];
        $table = $this->connection->prefixTable('terms');

        // Check if children exist
        $stmt = $this->connection->execute("SELECT COUNT(*) FROM `{$table}` WHERE `parent_id` = ?", [$termId]);
        $childCount = (int) $stmt->fetchColumn();
        if ($childCount > 0) {
            throw new InvalidArgumentException("Cannot delete term '{$termId}' because it has {$childCount} child terms. Move or delete children first.");
        }

        $relTable = $this->connection->prefixTable('term_relationships');

        $this->connection->transaction(function () use ($relTable, $table, $reassignToTermId, $termId, $taxonomyId) {
            if ($reassignToTermId !== null) {
                $targetTerm = $this->findById($reassignToTermId);
                if ($targetTerm === null || $targetTerm['taxonomy_id'] !== $taxonomyId) {
                    throw new InvalidArgumentException("Reassignment target term '{$reassignToTermId}' not found.");
                }

                // Reassign relationships, ignoring duplicates
                $this->connection->execute(
                    "UPDATE IGNORE `{$relTable}` SET `term_id` = ? WHERE `term_id` = ?",
                    [$reassignToTermId, $termId]
                );
                // Delete any remaining (which were duplicates)
                $this->connection->execute("DELETE FROM `{$relTable}` WHERE `term_id` = ?", [$termId]);
            } else {
                $this->connection->execute("DELETE FROM `{$relTable}` WHERE `term_id` = ?", [$termId]);
            }

            $this->connection->execute("DELETE FROM `{$table}` WHERE `id` = ?", [$termId]);
        });
    }

    /**
     * Merge source term into target term.
     * Reassigns all entry relationships, then deletes source term.
     */
    public function merge(string $sourceTermId, string $targetTermId): void
    {
        if ($sourceTermId === $targetTermId) {
            throw new InvalidArgumentException("Cannot merge term into itself.");
        }

        $source = $this->findById($sourceTermId);
        $target = $this->findById($targetTermId);
        if ($source === null || $target === null) {
            throw new InvalidArgumentException("Source or target term not found.");
        }

        if ($source['taxonomy_id'] !== $target['taxonomy_id']) {
            throw new InvalidArgumentException("Cannot merge terms across different taxonomies.");
        }

        $table = $this->connection->prefixTable('terms');
        // If source has children, reparent them to target
        $this->connection->execute(
            "UPDATE `{$table}` SET `parent_id` = ? WHERE `parent_id` = ?",
            [$targetTermId, $sourceTermId]
        );

        $this->delete($sourceTermId, $targetTermId);
    }

    /**
     * Recompute cached entry_count for terms in a taxonomy (or globally).
     */
    public function recomputeCounts(?string $taxonomyId = null): void
    {
        $termsTable = $this->connection->prefixTable('terms');
        $relTable = $this->connection->prefixTable('term_relationships');

        if ($taxonomyId !== null) {
            $sql = "UPDATE `{$termsTable}` t
                    SET `entry_count` = (
                        SELECT COUNT(DISTINCT r.entry_id)
                        FROM `{$relTable}` r
                        WHERE r.term_id = t.id AND r.is_published = 1
                    )
                    WHERE t.taxonomy_id = ?";
            $this->connection->execute($sql, [$taxonomyId]);
        } else {
            $sql = "UPDATE `{$termsTable}` t
                    SET `entry_count` = (
                        SELECT COUNT(DISTINCT r.entry_id)
                        FROM `{$relTable}` r
                        WHERE r.term_id = t.id AND r.is_published = 1
                    )";
            $this->connection->execute($sql);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatTerm(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'taxonomy_id' => (string) $row['taxonomy_id'],
            'parent_id' => $row['parent_id'] !== null ? (string) $row['parent_id'] : null,
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'description' => $row['description'],
            'path' => (string) $row['path'],
            'slug_path' => (string) $row['slug_path'],
            'depth' => (int) $row['depth'],
            'position' => (int) $row['position'],
            'image_media_id' => $row['image_media_id'],
            'color' => $row['color'],
            'icon' => $row['icon'],
            'data' => is_string($row['data']) ? json_decode($row['data'], true) : $row['data'],
            'seo' => is_string($row['seo']) ? json_decode($row['seo'], true) : $row['seo'],
            'entry_count' => (int) $row['entry_count'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
