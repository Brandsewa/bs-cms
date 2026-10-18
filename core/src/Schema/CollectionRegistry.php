<?php

declare(strict_types=1);

namespace BsCms\Schema;

use BsCms\Database\Connection;
use RuntimeException;

class CollectionRegistry
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $collections = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly ?string $cacheFile = null
    ) {
    }

    /**
     * Load all collections with their fields.
     * Uses compiled PHP cache file if fresh, otherwise reads from database and compiles cache.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        if ($this->collections !== null) {
            return $this->collections;
        }

        $cached = $this->loadFromCache();
        if ($cached !== null) {
            $this->collections = $cached;
            return $this->collections;
        }

        $data = $this->loadFromDatabase();
        $this->compileCache($data);
        $this->collections = $data;
        return $data;
    }

    /**
     * Get collection definition by handle.
     *
     * @return array<string, mixed>
     */
    public function get(string $handle): array
    {
        $all = $this->all();
        if (!isset($all[$handle])) {
            throw new RuntimeException("Collection '{$handle}' not found in registry.");
        }
        return $all[$handle];
    }

    public function has(string $handle): bool
    {
        $all = $this->all();
        return isset($all[$handle]);
    }

    /**
     * Invalidate compiled schema cache file.
     */
    public function invalidate(): void
    {
        $this->collections = null;
        if ($this->cacheFile !== null && file_exists($this->cacheFile)) {
            @unlink($this->cacheFile);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($this->cacheFile, true);
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    private function loadFromCache(): ?array
    {
        if ($this->cacheFile === null || !file_exists($this->cacheFile)) {
            return null;
        }

        try {
            /** @var mixed $data */
            $data = require $this->cacheFile;
            if (is_array($data) && isset($data['_schema_meta'])) {
                unset($data['_schema_meta']);
                /** @var array<string, array<string, mixed>> $data */
                return $data;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadFromDatabase(): array
    {
        $prefix = $this->connection->getPrefix();
        $colsStmt = $this->connection->execute(
            "SELECT * FROM `{$prefix}collections` ORDER BY `handle` ASC"
        );
        $collectionsRows = $colsStmt->fetchAll();

        $fieldsStmt = $this->connection->execute(
            "SELECT * FROM `{$prefix}fields` ORDER BY `position` ASC, `handle` ASC"
        );
        $fieldsRows = $fieldsStmt->fetchAll();

        $fieldsByColId = [];
        foreach ($fieldsRows as $fRow) {
            $colId = (string) $fRow['collection_id'];
            if (is_string($fRow['config'])) {
                $fRow['config'] = json_decode($fRow['config'], true) ?? [];
            }
            if (is_string($fRow['validation'])) {
                $fRow['validation'] = json_decode($fRow['validation'], true);
            }
            if (is_string($fRow['default_value'])) {
                $fRow['default_value'] = json_decode($fRow['default_value'], true);
            }
            if (is_string($fRow['conditional'])) {
                $fRow['conditional'] = json_decode($fRow['conditional'], true);
            }
            $fRow['required'] = (bool) $fRow['required'];
            $fRow['is_unique'] = (bool) $fRow['is_unique'];
            $fRow['is_filterable'] = (bool) $fRow['is_filterable'];
            $fRow['is_sortable'] = (bool) $fRow['is_sortable'];
            $fRow['is_searchable'] = (bool) $fRow['is_searchable'];
            $fRow['show_in_list'] = (bool) $fRow['show_in_list'];

            $fieldsByColId[$colId][$fRow['handle']] = $fRow;
        }

        $result = [];
        foreach ($collectionsRows as $cRow) {
            $handle = (string) $cRow['handle'];
            $id = (string) $cRow['id'];
            if (is_string($cRow['features'])) {
                $cRow['features'] = json_decode($cRow['features'], true) ?? [];
            }
            if (is_string($cRow['schema_org'])) {
                $cRow['schema_org'] = json_decode($cRow['schema_org'], true);
            }
            if (is_string($cRow['list_columns'])) {
                $cRow['list_columns'] = json_decode($cRow['list_columns'], true);
            }
            $cRow['is_system'] = (bool) $cRow['is_system'];
            $cRow['has_public_pages'] = (bool) $cRow['has_public_pages'];
            $cRow['fields'] = $fieldsByColId[$id] ?? [];

            $result[$handle] = $cRow;
        }

        return $result;
    }

    /**
     * @param array<string, array<string, mixed>> $collections
     */
    private function compileCache(array $collections): void
    {
        if ($this->cacheFile === null) {
            return;
        }

        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $payload = array_merge($collections, [
            '_schema_meta' => [
                'compiled_at' => date('c'),
            ],
        ]);

        $code = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($payload, true) . ";\n";

        $tmp = $this->cacheFile . '.' . bin2hex(random_bytes(6)) . '.tmp';
        file_put_contents($tmp, $code, LOCK_EX);
        rename($tmp, $this->cacheFile);

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->cacheFile, true);
        }
    }
}
