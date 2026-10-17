<?php

declare(strict_types=1);

namespace BsCms\Authz;

use BsCms\Cache\CacheInterface;
use BsCms\Database\Connection;
use PDO;

/**
 * Compiles and caches role permissions into fast in-memory lookup structures.
 */
final class PermissionCompiler
{
    /** @var array<string, list<Permission>> */
    private array $localMemory = [];
    private ?CacheInterface $cache;
    private int $version = 1;

    public function __construct(
        private readonly Connection $connection,
        ?CacheInterface $cache = null
    ) {
        $this->cache = $cache;
    }

    /**
     * Compile permissions for a list of role IDs.
     *
     * @param list<string> $roleIds
     * @return list<Permission>
     */
    public function compile(array $roleIds): array
    {
        if ($roleIds === []) {
            return [];
        }

        $sortedRoleIds = $roleIds;
        sort($sortedRoleIds);
        $cacheKey = 'authz_roles_' . md5(implode(':', $sortedRoleIds)) . '_v' . $this->version;

        if (isset($this->localMemory[$cacheKey])) {
            return $this->localMemory[$cacheKey];
        }

        if ($this->cache !== null) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) {
                /** @var list<Permission> $cached */
                $this->localMemory[$cacheKey] = $cached;
                return $cached;
            }
        }

        $table = $this->connection->prefixTable('role_permissions');
        $placeholders = implode(',', array_fill(0, count($sortedRoleIds), '?'));
        $stmt = $this->connection->getPdo()->prepare("SELECT * FROM `{$table}` WHERE `role_id` IN ({$placeholders}) ORDER BY `id` ASC");
        $stmt->execute($sortedRoleIds);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $permissions = [];
        foreach ($rows as $row) {
            $fields = null;
            if (!empty($row['fields'])) {
                /** @var array<string, mixed>|null $fields */
                $fields = is_string($row['fields']) ? json_decode($row['fields'], true) : (array) $row['fields'];
            }

            $conditions = null;
            if (!empty($row['conditions'])) {
                /** @var array<string, mixed>|null $conditions */
                $conditions = is_string($row['conditions']) ? json_decode($row['conditions'], true) : (array) $row['conditions'];
            }

            $permissions[] = new Permission(
                id: (string) $row['id'],
                roleId: (string) $row['role_id'],
                subjectType: (string) $row['subject_type'],
                subjectKey: (string) $row['subject_key'],
                action: (string) $row['action'],
                effect: (string) ($row['effect'] ?? 'allow'),
                fields: $fields,
                conditions: $conditions
            );
        }

        $this->localMemory[$cacheKey] = $permissions;

        if ($this->cache !== null) {
            $this->cache->set($cacheKey, $permissions, 3600);
        }

        return $permissions;
    }

    /**
     * Invalidate compiled permissions cache.
     */
    public function invalidate(): void
    {
        $this->localMemory = [];
        ++$this->version;
    }
}
