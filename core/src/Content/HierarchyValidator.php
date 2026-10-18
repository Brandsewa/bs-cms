<?php

declare(strict_types=1);

namespace BsCms\Content;

use BsCms\Database\Connection;
use InvalidArgumentException;

final class HierarchyValidator
{
    private const MAX_DEPTH = 6;

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Validate parent_id to prevent cycles, self-parenting, and depth exceeding 6.
     */
    public function validateParent(string $collectionHandle, ?string $parentId, ?string $entryId = null): void
    {
        if ($parentId === null || $parentId === '') {
            return;
        }

        if ($entryId !== null && $parentId === $entryId) {
            throw new InvalidArgumentException('An entry cannot be its own parent.');
        }

        $prefix = $this->connection->getPrefix();
        $tableName = $prefix . 'c_' . $collectionHandle;

        $visited = [];
        if ($entryId !== null) {
            $visited[$entryId] = true;
        }

        $currentParent = $parentId;
        $depth = 1;

        while ($currentParent !== null && $currentParent !== '') {
            if (isset($visited[$currentParent])) {
                throw new InvalidArgumentException('Cycle detected in hierarchy tree.');
            }
            $visited[$currentParent] = true;

            ++$depth;
            if ($depth > self::MAX_DEPTH) {
                throw new InvalidArgumentException(
                    sprintf('Hierarchy depth cannot exceed %d levels.', self::MAX_DEPTH)
                );
            }

            $stmt = $this->connection->execute(
                "SELECT `parent_id` FROM `{$tableName}` WHERE `id` = ?",
                [$currentParent]
            );
            $row = $stmt->fetch();
            if (!$row) {
                throw new InvalidArgumentException("Referenced parent entry '{$currentParent}' does not exist.");
            }

            $currentParent = $row['parent_id'] !== null ? (string) $row['parent_id'] : null;
        }
    }
}
