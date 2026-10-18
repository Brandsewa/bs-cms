<?php

declare(strict_types=1);

namespace BsCms\Content\Jobs;

use BsCms\Content\EntryRepository;
use BsCms\Database\Connection;
use BsCms\Schema\CollectionRegistry;
use BsCms\Support\Clock;

final class PublishScheduledJob
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CollectionRegistry $collectionRegistry,
        private readonly ?Clock $clock = null
    ) {
    }

    /**
     * Scan all collections for scheduled entries whose scheduled_at <= now, and publish them.
     *
     * @return int Number of published entries
     */
    public function handle(): int
    {
        $now = $this->clock !== null ? $this->clock->now()->format('Y-m-d H:i:s.v') : date('Y-m-d H:i:s.v');
        $collections = $this->collectionRegistry->all();
        $publishedCount = 0;
        $prefix = $this->connection->getPrefix();

        foreach ($collections as $handle => $def) {
            $tableName = $prefix . 'c_' . $handle;

            // Find due scheduled entries
            $stmt = $this->connection->execute(
                "SELECT `id`, `scheduled_at` FROM `{$tableName}`
                 WHERE `status` = 'scheduled' AND `scheduled_at` <= ? AND `deleted_at` IS NULL",
                [$now]
            );
            $dueEntries = $stmt->fetchAll();

            foreach ($dueEntries as $entry) {
                $id = (string) $entry['id'];
                $this->connection->execute(
                    "UPDATE `{$tableName}` SET `status` = 'published', `published_at` = ?, `scheduled_at` = NULL, `updated_at` = ?
                     WHERE `id` = ?",
                    [$now, $now, $id]
                );
                ++$publishedCount;
            }
        }

        return $publishedCount;
    }
}
