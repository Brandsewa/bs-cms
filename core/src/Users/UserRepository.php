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
 * UserRepository: data access for users and user_roles.
 */
final class UserRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Find a user by ID.
     *
     * @return array<string, mixed>|null
     */
    public function findById(string $id, bool $includeDeleted = false): ?array
    {
        $usersTable = $this->connection->prefixTable('users');
        $sql = "SELECT * FROM `{$usersTable}` WHERE `id` = ?";
        if (!$includeDeleted) {
            $sql .= " AND `deleted_at` IS NULL";
        }

        $stmt = $this->connection->getPdo()->prepare($sql);
        $stmt->execute([$id]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $row['roles'] = $this->getRoles($id);
        return $row;
    }

    /**
     * Find a user by email address.
     *
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email, bool $includeDeleted = false): ?array
    {
        $usersTable = $this->connection->prefixTable('users');
        $sql = "SELECT * FROM `{$usersTable}` WHERE `email` = ?";
        if (!$includeDeleted) {
            $sql .= " AND `deleted_at` IS NULL";
        }

        $stmt = $this->connection->getPdo()->prepare($sql);
        $stmt->execute([$email]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $row['roles'] = $this->getRoles((string) $row['id']);
        return $row;
    }

    /**
     * Create a new user with optional role assignments.
     *
     * @param array<string, mixed> $data
     * @param array<int, string> $roleIds
     */
    public function create(array $data, array $roleIds = []): string
    {
        $id = isset($data['id']) && is_string($data['id']) ? $data['id'] : Ulid::generate();
        $now = Clock::sql(Clock::now());

        $usersTable = $this->connection->prefixTable('users');
        $stmt = $this->connection->getPdo()->prepare(
            "INSERT INTO `{$usersTable}` (
                `id`, `email`, `email_verified_at`, `password_hash`, `name`,
                `avatar_media_id`, `status`, `locale`, `timezone`,
                `totp_secret_enc`, `totp_enabled_at`, `totp_last_step`, `recovery_codes_hash`,
                `failed_logins`, `locked_until`, `last_login_at`, `last_login_ip`,
                `created_at`, `updated_at`, `deleted_at`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)"
        );

        $recoveryCodesJson = isset($data['recovery_codes_hash']) && is_array($data['recovery_codes_hash'])
            ? Json::encode($data['recovery_codes_hash'])
            : (isset($data['recovery_codes_hash']) && is_string($data['recovery_codes_hash']) ? $data['recovery_codes_hash'] : null);

        $stmt->execute([
            $id,
            (string) ($data['email'] ?? ''),
            isset($data['email_verified_at']) ? (string) $data['email_verified_at'] : null,
            isset($data['password_hash']) ? (string) $data['password_hash'] : null,
            (string) ($data['name'] ?? ''),
            isset($data['avatar_media_id']) ? (string) $data['avatar_media_id'] : null,
            (string) ($data['status'] ?? 'active'),
            (string) ($data['locale'] ?? 'en'),
            (string) ($data['timezone'] ?? 'UTC'),
            isset($data['totp_secret_enc']) ? (string) $data['totp_secret_enc'] : null,
            isset($data['totp_enabled_at']) ? (string) $data['totp_enabled_at'] : null,
            isset($data['totp_last_step']) ? (int) $data['totp_last_step'] : null,
            $recoveryCodesJson,
            (int) ($data['failed_logins'] ?? 0),
            isset($data['locked_until']) ? (string) $data['locked_until'] : null,
            isset($data['last_login_at']) ? (string) $data['last_login_at'] : null,
            isset($data['last_login_ip']) ? (string) $data['last_login_ip'] : null,
            (string) ($data['created_at'] ?? $now),
            (string) ($data['updated_at'] ?? $now),
        ]);

        if (empty($roleIds)) {
            if (isset($data['role_id']) && is_string($data['role_id']) && $data['role_id'] !== '') {
                $roleIds = [$data['role_id']];
            } elseif (isset($data['roles']) && is_array($data['roles'])) {
                /** @var array<int, string> $roleIds */
                $roleIds = array_values($data['roles']);
            }
        }

        if (!empty($roleIds)) {
            $this->setRoles($id, $roleIds);
        }

        return $id;
    }

    /**
     * Update an existing user.
     *
     * @param array<string, mixed> $data
     */
    public function update(string $id, array $data): bool
    {
        $usersTable = $this->connection->prefixTable('users');
        $data['updated_at'] = Clock::sql(Clock::now());

        $sets = [];
        $params = [];

        foreach ($data as $col => $val) {
            if ($col === 'id' || $col === 'roles') {
                continue;
            }
            if ($col === 'recovery_codes_hash' && is_array($val)) {
                $val = Json::encode($val);
            }
            $sets[] = "`{$col}` = ?";
            $params[] = $val;
        }

        if (empty($sets)) {
            return false;
        }

        $params[] = $id;
        $sql = "UPDATE `{$usersTable}` SET " . implode(', ', $sets) . " WHERE `id` = ?";
        $stmt = $this->connection->getPdo()->prepare($sql);
        $stmt->execute($params);

        if (isset($data['roles']) && is_array($data['roles'])) {
            /** @var array<int, string> $roleIds */
            $roleIds = $data['roles'];
            $this->setRoles($id, $roleIds);
        }

        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a user (soft by default, or hard).
     */
    public function delete(string $id, bool $hard = false): bool
    {
        $usersTable = $this->connection->prefixTable('users');
        $pdo = $this->connection->getPdo();

        if ($hard) {
            $stmt = $pdo->prepare("DELETE FROM `{$usersTable}` WHERE `id` = ?");
            $stmt->execute([$id]);
            return $stmt->rowCount() > 0;
        }

        $now = Clock::sql(Clock::now());
        $stmt = $pdo->prepare("UPDATE `{$usersTable}` SET `deleted_at` = ? WHERE `id` = ?");
        $stmt->execute([$now, $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get all roles assigned to a user.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRoles(string $userId): array
    {
        $userRoles = $this->connection->prefixTable('user_roles');
        $roles = $this->connection->prefixTable('roles');

        $sql = "SELECT r.* FROM `{$roles}` r
                INNER JOIN `{$userRoles}` ur ON ur.role_id = r.id
                WHERE ur.user_id = ?
                ORDER BY r.name ASC";

        $stmt = $this->connection->getPdo()->prepare($sql);
        $stmt->execute([$userId]);
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * Replace all roles assigned to a user.
     *
     * @param array<int, string> $roleIds
     */
    public function setRoles(string $userId, array $roleIds): void
    {
        $userRoles = $this->connection->prefixTable('user_roles');
        $pdo = $this->connection->getPdo();

        $delStmt = $pdo->prepare("DELETE FROM `{$userRoles}` WHERE `user_id` = ?");
        $delStmt->execute([$userId]);

        if (empty($roleIds)) {
            return;
        }

        $insStmt = $pdo->prepare("INSERT INTO `{$userRoles}` (`user_id`, `role_id`) VALUES (?, ?)");
        foreach (array_unique($roleIds) as $roleId) {
            $insStmt->execute([$userId, $roleId]);
        }
    }

    /**
     * List users with optional filtering and cursor pagination.
     *
     * @param array<string, mixed> $filters
     * @return array{items: array<int, array<string, mixed>>, next_cursor: ?string}
     */
    public function list(
        array $filters = [],
        string $sort = 'created_at',
        string $direction = 'DESC',
        int $limit = 50,
        ?string $cursor = null
    ): array {
        $usersTable = $this->connection->prefixTable('users');
        $pdo = $this->connection->getPdo();

        $where = ['`deleted_at` IS NULL'];
        $params = [];

        if (isset($filters['status']) && is_string($filters['status'])) {
            $where[] = '`status` = ?';
            $params[] = $filters['status'];
        }

        if (isset($filters['search']) && is_string($filters['search']) && trim($filters['search']) !== '') {
            $search = '%' . trim($filters['search']) . '%';
            $where[] = '(`name` LIKE ? OR `email` LIKE ?)';
            $params[] = $search;
            $params[] = $search;
        }

        if ($cursor !== null) {
            $op = strtoupper($direction) === 'ASC' ? '>' : '<';
            $where[] = "`id` {$op} ?";
            $params[] = $cursor;
        }

        $whereSql = implode(' AND ', $where);
        $safeLimit = max(1, min($limit, 100));
        $fetchLimit = $safeLimit + 1;
        $dir = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';

        $sql = "SELECT * FROM `{$usersTable}` WHERE {$whereSql} ORDER BY `id` {$dir} LIMIT {$fetchLimit}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $nextCursor = null;
        if (count($rows) > $safeLimit) {
            array_pop($rows);
            $last = end($rows);
            $nextCursor = $last !== false ? (string) $last['id'] : null;
        }

        foreach ($rows as &$row) {
            $row['roles'] = $this->getRoles((string) $row['id']);
        }

        return [
            'items' => $rows,
            'next_cursor' => $nextCursor,
        ];
    }

    /**
     * Suspend user.
     */
    public function suspend(string $id): void
    {
        $this->update($id, ['status' => 'suspended']);
    }

    /**
     * Unsuspend user.
     */
    public function unsuspend(string $id): void
    {
        $this->update($id, ['status' => 'active']);
    }

    /**
     * Unlock locked account.
     */
    public function unlock(string $id): void
    {
        $this->update($id, [
            'failed_logins' => 0,
            'locked_until' => null,
        ]);
    }

    /**
     * Count active users with the 'owner' role.
     */
    public function countActiveOwners(): int
    {
        $usersTable = $this->connection->prefixTable('users');
        $rolesTable = $this->connection->prefixTable('roles');
        $userRolesTable = $this->connection->prefixTable('user_roles');

        $sql = "SELECT COUNT(DISTINCT u.id) FROM `{$usersTable}` u
                INNER JOIN `{$userRolesTable}` ur ON ur.user_id = u.id
                INNER JOIN `{$rolesTable}` r ON r.id = ur.role_id
                WHERE r.handle = 'owner'
                  AND u.status = 'active'
                  AND u.deleted_at IS NULL";

        $stmt = $this->connection->getPdo()->query($sql);
        if ($stmt === false) {
            return 0;
        }

        return (int) $stmt->fetchColumn();
    }
}
