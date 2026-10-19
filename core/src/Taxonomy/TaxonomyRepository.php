<?php

declare(strict_types=1);

namespace BsCms\Taxonomy;

use BsCms\Database\Connection;
use BsCms\Security\Ulid;
use BsCms\Support\Clock;
use InvalidArgumentException;
use PDO;

final class TaxonomyRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $table = $this->connection->prefixTable('taxonomies');
        $stmt = $this->connection->execute("SELECT * FROM `{$table}` ORDER BY `handle` ASC");
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'formatTaxonomy'], $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array
    {
        $table = $this->connection->prefixTable('taxonomies');
        $stmt = $this->connection->execute("SELECT * FROM `{$table}` WHERE `id` = ?", [$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return $this->formatTaxonomy($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByHandle(string $handle): ?array
    {
        $table = $this->connection->prefixTable('taxonomies');
        $stmt = $this->connection->execute("SELECT * FROM `{$table}` WHERE `handle` = ?", [$handle]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return $this->formatTaxonomy($row);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $handle = (string) ($data['handle'] ?? '');
        \BsCms\Schema\IdentifierValidator::validateTaxonomyHandle($handle);

        $id = Ulid::generate();
        $now = Clock::sql(Clock::now());
        $table = $this->connection->prefixTable('taxonomies');

        $this->connection->execute(
            "INSERT INTO `{$table}` (
                `id`, `handle`, `label_singular`, `label_plural`, `description`,
                `hierarchical`, `max_depth`, `has_public_archive`, `archive_route_pattern`,
                `is_system`, `plugin_key`, `created_at`, `updated_at`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $id,
                $handle,
                (string) ($data['label_singular'] ?? $handle),
                (string) ($data['label_plural'] ?? ($handle . 's')),
                $data['description'] ?? null,
                !empty($data['hierarchical']) ? 1 : 0,
                isset($data['max_depth']) ? (int) $data['max_depth'] : null,
                isset($data['has_public_archive']) ? (!empty($data['has_public_archive']) ? 1 : 0) : 1,
                $data['archive_route_pattern'] ?? null,
                !empty($data['is_system']) ? 1 : 0,
                $data['plugin_key'] ?? null,
                $now,
                $now,
            ]
        );

        return $this->findById($id) ?? [];
    }

    /**
     * Attach a taxonomy to a collection.
     *
     * @return array<string, mixed>
     */
    public function attach(
        string $taxonomyHandle,
        string $collectionHandle,
        string $fieldKey,
        bool $required = false,
        bool $allowMultiple = true,
        int $position = 0
    ): array {
        $taxonomy = $this->findByHandle($taxonomyHandle);
        if ($taxonomy === null) {
            throw new InvalidArgumentException("Taxonomy '{$taxonomyHandle}' not found.");
        }

        $colTable = $this->connection->prefixTable('collections');
        $stmt = $this->connection->execute("SELECT * FROM `{$colTable}` WHERE `handle` = ?", [$collectionHandle]);
        $collection = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$collection) {
            throw new InvalidArgumentException("Collection '{$collectionHandle}' not found.");
        }

        $colTaxTable = $this->connection->prefixTable('collection_taxonomies');
        $this->connection->execute(
            "INSERT INTO `{$colTaxTable}` (`collection_id`, `taxonomy_id`, `required`, `allow_multiple`, `field_key`, `position`)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE `required` = VALUES(`required`), `allow_multiple` = VALUES(`allow_multiple`), `field_key` = VALUES(`field_key`), `position` = VALUES(`position`)",
            [
                $collection['id'],
                $taxonomy['id'],
                $required ? 1 : 0,
                $allowMultiple ? 1 : 0,
                $fieldKey,
                $position
            ]
        );

        return [
            'taxonomy_id' => $taxonomy['id'],
            'taxonomy_handle' => $taxonomyHandle,
            'collection_id' => $collection['id'],
            'collection_handle' => $collectionHandle,
            'field_key' => $fieldKey,
            'required' => $required,
            'allow_multiple' => $allowMultiple,
            'position' => $position,
        ];
    }

    /**
     * Detach a taxonomy from a collection.
     */
    public function detach(string $taxonomyHandle, string $collectionHandle): void
    {
        $taxonomy = $this->findByHandle($taxonomyHandle);
        if ($taxonomy === null) {
            return;
        }

        $colTable = $this->connection->prefixTable('collections');
        $stmt = $this->connection->execute("SELECT `id` FROM `{$colTable}` WHERE `handle` = ?", [$collectionHandle]);
        $colId = $stmt->fetchColumn();
        if (!$colId) {
            return;
        }

        $colTaxTable = $this->connection->prefixTable('collection_taxonomies');
        $this->connection->execute(
            "DELETE FROM `{$colTaxTable}` WHERE `collection_id` = ? AND `taxonomy_id` = ?",
            [$colId, $taxonomy['id']]
        );
    }

    /**
     * Get all attachments for a taxonomy.
     *
     * @return list<array<string, mixed>>
     */
    public function getAttachments(string $taxonomyHandle): array
    {
        $taxonomy = $this->findByHandle($taxonomyHandle);
        if ($taxonomy === null) {
            return [];
        }

        $colTaxTable = $this->connection->prefixTable('collection_taxonomies');
        $colTable = $this->connection->prefixTable('collections');

        $stmt = $this->connection->execute(
            "SELECT ct.*, c.`handle` AS `collection_handle`
             FROM `{$colTaxTable}` ct
             JOIN `{$colTable}` c ON c.`id` = ct.`collection_id`
             WHERE ct.`taxonomy_id` = ?
             ORDER BY ct.`position` ASC",
            [$taxonomy['id']]
        );

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(string $handle, array $data): array
    {
        $existing = $this->findByHandle($handle);
        if ($existing === null) {
            throw new InvalidArgumentException("Taxonomy '{$handle}' not found.");
        }

        $now = Clock::sql(Clock::now());
        $table = $this->connection->prefixTable('taxonomies');

        $labelSingular = (string) ($data['label_singular'] ?? $existing['label_singular']);
        $labelPlural = (string) ($data['label_plural'] ?? $existing['label_plural']);
        $description = array_key_exists('description', $data) ? $data['description'] : $existing['description'];
        $maxDepth = array_key_exists('max_depth', $data) ? ($data['max_depth'] !== null ? (int) $data['max_depth'] : null) : $existing['max_depth'];
        $hasPublicArchive = array_key_exists('has_public_archive', $data) ? (!empty($data['has_public_archive']) ? 1 : 0) : ($existing['has_public_archive'] ? 1 : 0);
        $archiveRoutePattern = array_key_exists('archive_route_pattern', $data) ? $data['archive_route_pattern'] : $existing['archive_route_pattern'];

        $this->connection->execute(
            "UPDATE `{$table}` SET
                `label_singular` = ?,
                `label_plural` = ?,
                `description` = ?,
                `max_depth` = ?,
                `has_public_archive` = ?,
                `archive_route_pattern` = ?,
                `updated_at` = ?
             WHERE `handle` = ?",
            [
                $labelSingular,
                $labelPlural,
                $description,
                $maxDepth,
                $hasPublicArchive,
                $archiveRoutePattern,
                $now,
                $handle,
            ]
        );

        return $this->findByHandle($handle) ?? [];
    }

    public function delete(string $handle): void
    {
        $existing = $this->findByHandle($handle);
        if ($existing === null) {
            return;
        }

        if (!empty($existing['is_system'])) {
            throw new InvalidArgumentException("Cannot delete system taxonomy '{$handle}'.");
        }

        $table = $this->connection->prefixTable('taxonomies');
        $this->connection->execute("DELETE FROM `{$table}` WHERE `handle` = ?", [$handle]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatTaxonomy(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'handle' => (string) $row['handle'],
            'label_singular' => (string) $row['label_singular'],
            'label_plural' => (string) $row['label_plural'],
            'description' => $row['description'],
            'hierarchical' => (bool) $row['hierarchical'],
            'max_depth' => $row['max_depth'] !== null ? (int) $row['max_depth'] : null,
            'has_public_archive' => (bool) $row['has_public_archive'],
            'archive_route_pattern' => $row['archive_route_pattern'],
            'is_system' => (bool) $row['is_system'],
            'plugin_key' => $row['plugin_key'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
