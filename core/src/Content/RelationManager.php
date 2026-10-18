<?php

declare(strict_types=1);

namespace BsCms\Content;

use BsCms\Database\Connection;

final class RelationManager
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Synchronize relations for a field on an entry.
     *
     * @param list<string> $targetEntryIds
     */
    public function sync(
        string $fromCollectionId,
        string $fromEntryId,
        string $fieldKey,
        string $toCollectionId,
        array $targetEntryIds
    ): void {
        $prefix = $this->connection->getPrefix();

        // Delete existing relations for this field
        $this->connection->execute(
            "DELETE FROM `{$prefix}entry_relations`
             WHERE `from_collection_id` = ? AND `from_entry_id` = ? AND `field_key` = ?",
            [$fromCollectionId, $fromEntryId, $fieldKey]
        );

        $pos = 0;
        foreach ($targetEntryIds as $toEntryId) {
            ++$pos;
            $this->connection->execute(
                "INSERT INTO `{$prefix}entry_relations` (
                    `from_collection_id`, `from_entry_id`, `field_key`, `to_collection_id`, `to_entry_id`, `position`
                ) VALUES (?, ?, ?, ?, ?, ?)",
                [$fromCollectionId, $fromEntryId, $fieldKey, $toCollectionId, $toEntryId, $pos]
            );
        }
    }

    /**
     * Clean all relations when an entry is permanently purged.
     */
    public function purgeEntry(string $collectionId, string $entryId): void
    {
        $prefix = $this->connection->getPrefix();

        $this->connection->execute(
            "DELETE FROM `{$prefix}entry_relations`
             WHERE (`from_collection_id` = ? AND `from_entry_id` = ?)
                OR (`to_collection_id` = ? AND `to_entry_id` = ?)",
            [$collectionId, $entryId, $collectionId, $entryId]
        );
    }

    /**
     * Fetch target entry IDs for a relation field.
     *
     * @return list<string>
     */
    public function getRelatedEntryIds(string $fromCollectionId, string $fromEntryId, string $fieldKey): array
    {
        $prefix = $this->connection->getPrefix();
        $stmt = $this->connection->execute(
            "SELECT `to_entry_id` FROM `{$prefix}entry_relations`
             WHERE `from_collection_id` = ? AND `from_entry_id` = ? AND `field_key` = ?
             ORDER BY `position` ASC",
            [$fromCollectionId, $fromEntryId, $fieldKey]
        );

        $rows = $stmt->fetchAll();
        return array_values(array_map(static fn (array $r): string => (string) $r['to_entry_id'], $rows));
    }
}
