<?php

declare(strict_types=1);

namespace BsCms\Media;

use BsCms\Database\Connection;
use BsCms\Security\Ulid;
use BsCms\Support\Clock;
use InvalidArgumentException;
use PDO;

final class MediaFolderRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function create(string $name, ?string $parentId = null): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException("Folder name cannot be empty.");
        }

        if (str_contains($name, '/') || str_contains($name, chr(92)) || mb_strlen($name) > 120) {
            throw new InvalidArgumentException("Folder name must be at most 120 characters and cannot contain slashes.");
        }

        $table = $this->connection->prefixTable('media_folders');
        $id = Ulid::generate();
        $now = Clock::sql(Clock::now());

        $path = '/' . $name . '/';
        if ($parentId !== null) {
            $parent = $this->findById($parentId);
            if ($parent === null) {
                throw new InvalidArgumentException("Parent folder '{$parentId}' not found.");
            }
            $path = (string) $parent['path'] . $name . '/';
        }

        $parentKey = $parentId ?? '';

        $this->connection->execute(
            "INSERT INTO `{$table}` (`id`, `parent_id`, `parent_key`, `name`, `path`, `created_at`) VALUES (?, ?, ?, ?, ?, ?)",
            [$id, $parentId, $parentKey, $name, $path, $now]
        );

        return $this->findById($id) ?? [];
    }

    /**
     * @param array{name?: string, parent_id?: ?string} $data
     * @return array<string, mixed>
     */
    public function update(string $id, array $data): array
    {
        $folder = $this->findById($id);
        if ($folder === null) {
            throw new InvalidArgumentException("Folder '{$id}' not found.");
        }

        $newName = isset($data['name']) ? trim($data['name']) : (string) $folder['name'];
        if ($newName === '') {
            throw new InvalidArgumentException("Folder name cannot be empty.");
        }

        $newParentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $folder['parent_id'];
        if ($newParentId !== null && (string) $newParentId === '') {
            $newParentId = null;
        }

        if ($newParentId === $id) {
            throw new InvalidArgumentException("Folder cannot be its own parent.");
        }

        $oldPath = (string) $folder['path'];
        $newPath = '/' . $newName . '/';

        if ($newParentId !== null) {
            $parent = $this->findById((string) $newParentId);
            if ($parent === null) {
                throw new InvalidArgumentException("Parent folder '{$newParentId}' not found.");
            }

            // Cycle prevention: new parent cannot be a descendant of current folder
            $parentPath = (string) $parent['path'];
            if (str_starts_with($parentPath, $oldPath)) {
                throw new InvalidArgumentException("Cannot move a folder into one of its own subfolders.");
            }

            $newPath = $parentPath . $newName . '/';
        }

        $parentKey = $newParentId ?? '';
        $table = $this->connection->prefixTable('media_folders');

        $this->connection->transaction(function () use ($table, $id, $newParentId, $parentKey, $newName, $newPath, $oldPath) {
            $this->connection->execute(
                "UPDATE `{$table}` SET `name` = ?, `parent_id` = ?, `parent_key` = ?, `path` = ? WHERE `id` = ?",
                [$newName, $newParentId, $parentKey, $newPath, $id]
            );

            // Update all descendant folder paths
            $escapedOldPath = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $oldPath);
            $stmt = $this->connection->execute(
                "SELECT `id`, `path` FROM `{$table}` WHERE `path` LIKE ? AND `id` != ?",
                [$escapedOldPath . '%', $id]
            );
            /** @var list<array<string, mixed>> $descendants */
            $descendants = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($descendants as $desc) {
                $subOldPath = (string) $desc['path'];
                $subNewPath = $newPath . substr($subOldPath, strlen($oldPath));
                $this->connection->execute(
                    "UPDATE `{$table}` SET `path` = ? WHERE `id` = ?",
                    [$subNewPath, $desc['id']]
                );
            }
        });

        return $this->findById($id) ?? [];
    }

    /**
     * Move folder under a new parent (or to root if null).
     *
     * @return array<string, mixed>
     */
    public function move(string $id, ?string $newParentId): array
    {
        return $this->update($id, ['parent_id' => $newParentId]);
    }

    public function delete(string $id): void
    {
        $folder = $this->findById($id);
        if ($folder === null) {
            return;
        }

        $table = $this->connection->prefixTable('media_folders');
        $mediaTable = $this->connection->prefixTable('media');

        // Check for subfolders
        $subStmt = $this->connection->execute("SELECT COUNT(*) FROM `{$table}` WHERE `parent_id` = ?", [$id]);
        if ((int) $subStmt->fetchColumn() > 0) {
            throw new InvalidArgumentException("Cannot delete folder '{$id}' because it contains subfolders.");
        }

        // Check for media in this folder
        $mediaStmt = $this->connection->execute("SELECT COUNT(*) FROM `{$mediaTable}` WHERE `folder_id` = ? AND `deleted_at` IS NULL", [$id]);
        if ((int) $mediaStmt->fetchColumn() > 0) {
            throw new InvalidArgumentException("Cannot delete folder '{$id}' because it contains media files.");
        }

        $this->connection->execute("DELETE FROM `{$table}` WHERE `id` = ?", [$id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array
    {
        $table = $this->connection->prefixTable('media_folders');
        $stmt = $this->connection->execute("SELECT * FROM `{$table}` WHERE `id` = ?", [$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?string $parentId = null): array
    {
        $table = $this->connection->prefixTable('media_folders');
        if ($parentId !== null) {
            $stmt = $this->connection->execute("SELECT * FROM `{$table}` WHERE `parent_id` = ? ORDER BY `name` ASC", [$parentId]);
        } else {
            $stmt = $this->connection->execute("SELECT * FROM `{$table}` ORDER BY `path` ASC, `name` ASC");
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }
}
