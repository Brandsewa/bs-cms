<?php

declare(strict_types=1);

namespace BsCms\Users;

use BsCms\Database\Connection;
use BsCms\Security\Ulid;
use BsCms\Support\Clock;
use BsCms\Support\Json;
use PDO;
use RuntimeException;

/**
 * RoleRepository: manages roles and their granular role_permissions rows.
 */
final class RoleRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Find a role by its ID.
     *
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array
    {
        $rolesTable = $this->connection->prefixTable('roles');
        $stmt = $this->connection->getPdo()->prepare("SELECT * FROM `{$rolesTable}` WHERE `id` = ?");
        $stmt->execute([$id]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $row['permissions'] = $this->getPermissions($id);
        return $row;
    }

    /**
     * Find a role by its unique handle.
     *
     * @return array<string, mixed>|null
     */
    public function findByHandle(string $handle): ?array
    {
        $rolesTable = $this->connection->prefixTable('roles');
        $stmt = $this->connection->getPdo()->prepare("SELECT * FROM `{$rolesTable}` WHERE `handle` = ?");
        $stmt->execute([$handle]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $row['permissions'] = $this->getPermissions((string) $row['id']);
        return $row;
    }

    /**
     * List all roles with their permissions.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        $rolesTable = $this->connection->prefixTable('roles');
        $stmt = $this->connection->getPdo()->query("SELECT * FROM `{$rolesTable}` ORDER BY `is_system` DESC, `name` ASC");
        if ($stmt === false) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['permissions'] = $this->getPermissions((string) $row['id']);
        }

        return $rows;
    }

    /**
     * Create a new role with optional initial permissions.
     *
     * @param array<string, mixed> $data
     * @param array<int, array<string, mixed>> $permissions
     */
    public function create(array $data, array $permissions = []): string
    {
        $id = isset($data['id']) && is_string($data['id']) ? $data['id'] : Ulid::generate();
        $now = Clock::sql(Clock::now());

        $rolesTable = $this->connection->prefixTable('roles');
        $stmt = $this->connection->getPdo()->prepare(
            "INSERT INTO `{$rolesTable}` (`id`, `handle`, `name`, `description`, `is_system`, `is_ai`, `created_at`, `updated_at`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $id,
            (string) ($data['handle'] ?? ''),
            (string) ($data['name'] ?? ''),
            isset($data['description']) ? (string) $data['description'] : null,
            (int) ($data['is_system'] ?? 0),
            (int) ($data['is_ai'] ?? 0),
            (string) ($data['created_at'] ?? $now),
            (string) ($data['updated_at'] ?? $now),
        ]);

        if (!empty($permissions)) {
            $this->setPermissions($id, $permissions);
        }

        return $id;
    }

    /**
     * Update role details. System role handles cannot be changed.
     *
     * @param array<string, mixed> $data
     */
    public function update(string $id, array $data): bool
    {
        $role = $this->findById($id);
        if ($role === null) {
            return false;
        }

        // Protect system roles from having handle modified
        if ((int) $role['is_system'] === 1 && isset($data['handle']) && $data['handle'] !== $role['handle']) {
            throw new RuntimeException("Cannot alter handle of system role '{$role['handle']}'.");
        }

        $rolesTable = $this->connection->prefixTable('roles');
        $data['updated_at'] = Clock::sql(Clock::now());

        $sets = [];
        $params = [];

        foreach ($data as $col => $val) {
            if ($col === 'id' || $col === 'permissions' || $col === 'is_system') {
                continue;
            }
            $sets[] = "`{$col}` = ?";
            $params[] = $val;
        }

        if (empty($sets)) {
            return false;
        }

        $params[] = $id;
        $sql = "UPDATE `{$rolesTable}` SET " . implode(', ', $sets) . " WHERE `id` = ?";
        $stmt = $this->connection->getPdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a role. Fails if role is a system role or has assigned users.
     */
    public function delete(string $id): bool
    {
        $role = $this->findById($id);
        if ($role === null) {
            return false;
        }

        if ((int) $role['is_system'] === 1) {
            throw new RuntimeException("Cannot delete system role '{$role['handle']}'.");
        }

        if ($this->hasAssignedUsers($id)) {
            throw new RuntimeException("Cannot delete role '{$role['name']}': active users are assigned to it.");
        }

        $rolesTable = $this->connection->prefixTable('roles');
        $stmt = $this->connection->getPdo()->prepare("DELETE FROM `{$rolesTable}` WHERE `id` = ?");
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Check if any users are assigned to a role.
     */
    public function hasAssignedUsers(string $roleId): bool
    {
        $userRoles = $this->connection->prefixTable('user_roles');
        $stmt = $this->connection->getPdo()->prepare("SELECT COUNT(*) FROM `{$userRoles}` WHERE `role_id` = ?");
        $stmt->execute([$roleId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Get permission rules for a role.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPermissions(string $roleId): array
    {
        $permTable = $this->connection->prefixTable('role_permissions');
        $stmt = $this->connection->getPdo()->prepare("SELECT * FROM `{$permTable}` WHERE `role_id` = ? ORDER BY `id` ASC");
        $stmt->execute([$roleId]);
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            if (isset($row['fields']) && is_string($row['fields'])) {
                $row['fields'] = Json::decode($row['fields']);
            }
            if (isset($row['conditions']) && is_string($row['conditions'])) {
                $row['conditions'] = Json::decode($row['conditions']);
            }
        }

        return $rows;
    }

    /**
     * Atomically replace all permissions for a role.
     * Note: 'owner' role permissions are strictly immutable.
     *
     * @param array<int, array<string, mixed>> $permissions
     */
    public function setPermissions(string $roleId, array $permissions): void
    {
        $role = $this->findById($roleId);
        if ($role !== null && $role['handle'] === 'owner') {
            throw new RuntimeException("Owner role permissions are immutable and cannot be modified.");
        }

        $permTable = $this->connection->prefixTable('role_permissions');
        $pdo = $this->connection->getPdo();
        $now = Clock::sql(Clock::now());

        $delStmt = $pdo->prepare("DELETE FROM `{$permTable}` WHERE `role_id` = ?");
        $delStmt->execute([$roleId]);

        $insStmt = $pdo->prepare(
            "INSERT INTO `{$permTable}` (`id`, `role_id`, `subject_type`, `subject_key`, `action`, `effect`, `fields`, `conditions`, `created_at`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        foreach ($permissions as $perm) {
            $id = isset($perm['id']) && is_string($perm['id']) ? $perm['id'] : Ulid::generate();
            $fields = isset($perm['fields']) && is_array($perm['fields']) ? Json::encode($perm['fields']) : null;
            $conditions = isset($perm['conditions']) && is_array($perm['conditions']) ? Json::encode($perm['conditions']) : null;

            $insStmt->execute([
                $id,
                $roleId,
                (string) ($perm['subject_type'] ?? '*'),
                (string) ($perm['subject_key'] ?? '*'),
                (string) ($perm['action'] ?? '*'),
                (string) ($perm['effect'] ?? 'allow'),
                $fields,
                $conditions,
                $now,
            ]);
        }
    }

    /**
     * Clone an existing role and its permissions into a new role.
     */
    public function clone(string $sourceRoleId, string $newHandle, string $newName, ?string $newDescription = null): string
    {
        $source = $this->findById($sourceRoleId);
        if ($source === null) {
            throw new RuntimeException("Source role '{$sourceRoleId}' not found.");
        }

        $newRoleId = $this->create([
            'handle' => $newHandle,
            'name' => $newName,
            'description' => $newDescription ?? ($source['description'] !== null ? "Clone of {$source['name']}" : null),
            'is_system' => 0,
            'is_ai' => (int) $source['is_ai'],
        ]);

        /** @var array<int, array<string, mixed>> $perms */
        $perms = $source['permissions'];
        $cleanPerms = [];
        foreach ($perms as $p) {
            unset($p['id'], $p['role_id'], $p['created_at']);
            $cleanPerms[] = $p;
        }

        $this->setPermissions($newRoleId, $cleanPerms);

        return $newRoleId;
    }
}
