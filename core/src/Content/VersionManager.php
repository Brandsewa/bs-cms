<?php

declare(strict_types=1);

namespace BsCms\Content;

use BsCms\Database\Connection;
use BsCms\Security\Ulid;
use BsCms\Support\Clock;

final class VersionManager
{
    private const AUTOSAVE_THROTTLE_SECONDS = 30;

    public function __construct(
        private readonly Connection $connection,
        private readonly ?Clock $clock = null
    ) {
    }

    /**
     * Record an entry version.
     * Autosaves from the same user within 30 seconds update the existing autosave row rather than creating a new version.
     *
     * @param array<string, mixed> $snapshot
     */
    public function recordVersion(
        string $collectionId,
        string $entryId,
        string $status,
        array $snapshot,
        ?string $changeSummary = null,
        string $actorType = 'user',
        ?string $actorLabel = null,
        ?string $createdBy = null,
        bool $isAutosave = false
    ): int {
        $prefix = $this->connection->getPrefix();
        $now = $this->clock !== null ? $this->clock->now()->format('Y-m-d H:i:s.v') : date('Y-m-d H:i:s.v');

        // Check for autosave throttling
        if ($isAutosave && $createdBy !== null) {
            $stmt = $this->connection->execute(
                "SELECT `id`, `version_no`, `created_at` FROM `{$prefix}entry_versions`
                 WHERE `collection_id` = ? AND `entry_id` = ? AND `created_by` = ? AND `is_autosave` = 1
                 ORDER BY `version_no` DESC LIMIT 1",
                [$collectionId, $entryId, $createdBy]
            );
            $lastAutosave = $stmt->fetch();

            if ($lastAutosave) {
                $lastTime = strtotime((string) $lastAutosave['created_at']);
                $currentTime = strtotime($now);
                if (($currentTime - $lastTime) < self::AUTOSAVE_THROTTLE_SECONDS) {
                    $this->connection->execute(
                        "UPDATE `{$prefix}entry_versions` SET `snapshot` = ?, `created_at` = ? WHERE `id` = ?",
                        [json_encode($snapshot, JSON_THROW_ON_ERROR), $now, $lastAutosave['id']]
                    );
                    return (int) $lastAutosave['version_no'];
                }
            }
        }

        // Get next version number
        $vStmt = $this->connection->execute(
            "SELECT MAX(`version_no`) AS max_v FROM `{$prefix}entry_versions` WHERE `collection_id` = ? AND `entry_id` = ?",
            [$collectionId, $entryId]
        );
        $vRow = $vStmt->fetch();
        $nextVersion = ($vRow && $vRow['max_v'] !== null) ? ((int) $vRow['max_v'] + 1) : 1;

        $id = Ulid::generate();
        $this->connection->execute(
            "INSERT INTO `{$prefix}entry_versions` (
                `id`, `collection_id`, `entry_id`, `version_no`, `status`, `snapshot`,
                `change_summary`, `actor_type`, `actor_label`, `created_by`, `is_autosave`, `created_at`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $id,
                $collectionId,
                $entryId,
                $nextVersion,
                $status,
                json_encode($snapshot, JSON_THROW_ON_ERROR),
                $changeSummary,
                $actorType,
                $actorLabel,
                $createdBy,
                $isAutosave ? 1 : 0,
                $now,
            ]
        );

        return $nextVersion;
    }

    /**
     * Compare two versions and return a field-level diff.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function compare(string $collectionId, string $entryId, int $versionFrom, int $versionTo): array
    {
        $prefix = $this->connection->getPrefix();
        $stmt = $this->connection->execute(
            "SELECT `version_no`, `snapshot` FROM `{$prefix}entry_versions`
             WHERE `collection_id` = ? AND `entry_id` = ? AND `version_no` IN (?, ?)",
            [$collectionId, $entryId, $versionFrom, $versionTo]
        );
        $rows = $stmt->fetchAll();

        $fromData = [];
        $toData = [];

        foreach ($rows as $row) {
            $vNo = (int) $row['version_no'];
            $data = is_string($row['snapshot']) ? json_decode($row['snapshot'], true) : [];
            if ($vNo === $versionFrom) {
                $fromData = (array) $data;
            }
            if ($vNo === $versionTo) {
                $toData = (array) $data;
            }
        }

        $allKeys = array_unique(array_merge(array_keys($fromData), array_keys($toData)));
        $diff = [];

        foreach ($allKeys as $key) {
            $oldVal = $fromData[$key] ?? null;
            $newVal = $toData[$key] ?? null;

            if ($oldVal !== $newVal) {
                $diff[$key] = [
                    'old' => $oldVal,
                    'new' => $newVal,
                ];
            }
        }

        return $diff;
    }

    /**
     * Get a specific snapshot by version number.
     *
     * @return array<string, mixed>|null
     */
    public function getVersion(string $collectionId, string $entryId, int $versionNo): ?array
    {
        $prefix = $this->connection->getPrefix();
        $stmt = $this->connection->execute(
            "SELECT * FROM `{$prefix}entry_versions` WHERE `collection_id` = ? AND `entry_id` = ? AND `version_no` = ?",
            [$collectionId, $entryId, $versionNo]
        );
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $row['snapshot'] = is_string($row['snapshot']) ? json_decode($row['snapshot'], true) : [];
        return $row;
    }
}
