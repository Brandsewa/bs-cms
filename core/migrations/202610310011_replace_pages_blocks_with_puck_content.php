<?php

declare(strict_types=1);

namespace BsCms\Migrations;

use BsCms\Database\Migration;
use BsCms\Security\Ulid;
use Throwable;

/**
 * Migration 0011: Replace the legacy "blocks" field on pages with the Puck
 * visual-editor "content" field.
 *
 * - fields table: drop the pages.blocks row, insert pages.content (type 'puck').
 * - c_pages.data: convert legacy `blocks` arrays to PuckData under `content`.
 * - entry_versions.snapshot: apply the same conversion (best-effort) to the
 *   embedded entry data, or strip the stale `blocks` key.
 */
final class ReplacePagesBlocksWithPuckContent extends Migration
{
    public function up(): void
    {
        $collections = $this->connection->prefixTable('collections');
        $fields = $this->connection->prefixTable('fields');
        $entryVersions = $this->connection->prefixTable('entry_versions');
        $pagesTable = $this->connection->prefixTable('c_pages');
        $pdo = $this->connection->getPdo();
        $now = date('Y-m-d H:i:s.v');

        // 1. Find pages collection ID
        $stmt = $pdo->prepare("SELECT `id` FROM `{$collections}` WHERE `handle` = 'pages' LIMIT 1");
        $stmt->execute();
        $pagesColId = $stmt->fetchColumn();

        if ($pagesColId === false) {
            // Fresh install without seeded collections: nothing to migrate.
            return;
        }

        // 2. Swap the fields-table row: delete 'blocks', insert 'content'.
        $position = 0;
        $lookup = $pdo->prepare("SELECT `position` FROM `{$fields}` WHERE `collection_id` = ? AND `handle` = 'blocks'");
        $lookup->execute([$pagesColId]);
        $foundPosition = $lookup->fetchColumn();
        if ($foundPosition !== false) {
            $position = (int) $foundPosition;
        }

        $delete = $pdo->prepare("DELETE FROM `{$fields}` WHERE `collection_id` = ? AND `handle` = 'blocks'");
        $delete->execute([$pagesColId]);

        // Idempotent for fresh installs where the baseline already seeded 'content'.
        $exists = $pdo->prepare("SELECT `id` FROM `{$fields}` WHERE `collection_id` = ? AND `handle` = 'content'");
        $exists->execute([$pagesColId]);
        if ($exists->fetchColumn() === false) {
            $insert = $pdo->prepare(
                "INSERT INTO `{$fields}` (
                    `id`, `collection_id`, `handle`, `label`, `type`, `config`,
                    `required`, `is_unique`, `is_filterable`, `is_sortable`,
                    `is_searchable`, `show_in_list`, `default_value`, `help_text`,
                    `placeholder`, `group_key`, `position`, `width`,
                    `validation`, `conditional`, `storage`, `column_name`,
                    `created_at`, `updated_at`
                ) VALUES (?, ?, 'content', 'Page Content', 'puck', '{}', 0, 0, 0, 0, 0, 0, NULL, NULL, NULL, NULL, ?, 'full', NULL, NULL, 'json', NULL, ?, ?)"
            );
            // Order: id, collection_id, position, created_at, updated_at
            $insert->execute([Ulid::generate(), $pagesColId, $position, $now, $now]);
        }

        // 3. Convert c_pages.data.blocks -> data.content (PuckData).
        try {
            $rows = $pdo->query("SELECT `id`, `data` FROM `{$pagesTable}` WHERE `data` LIKE '%blocks%'");
        } catch (Throwable) {
            $rows = false; // c_pages may not exist yet on brand-new installs
        }
        if ($rows !== false) {
            $update = $pdo->prepare("UPDATE `{$pagesTable}` SET `data` = ?, `updated_at` = ? WHERE `id` = ?");
            while ($row = $rows->fetch(\PDO::FETCH_ASSOC)) {
                $data = json_decode((string) ($row['data'] ?? ''), true);
                if (!is_array($data) || !array_key_exists('blocks', $data)) {
                    continue;
                }
                $puck = is_array($data['blocks'])
                    ? self::convertLegacyBlocksToPuck($data['blocks'])
                    : ['content' => [], 'root' => ['props' => []]];
                unset($data['blocks']);
                $data['content'] = $puck;
                $update->execute([
                    json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $now,
                    (string) $row['id'],
                ]);
            }
        }

        // 4. Best-effort conversion of entry_versions snapshots for pages.
        try {
            $versionRows = $pdo->prepare(
                "SELECT `id`, `snapshot` FROM `{$entryVersions}` WHERE `collection_id` = ? AND `snapshot` LIKE '%blocks%'"
            );
            $versionRows->execute([$pagesColId]);
        } catch (Throwable) {
            $versionRows = false;
        }
        if ($versionRows !== false) {
            $updateVersion = $pdo->prepare("UPDATE `{$entryVersions}` SET `snapshot` = ? WHERE `id` = ?");
            while ($versionRow = $versionRows->fetch(\PDO::FETCH_ASSOC)) {
                $snapshot = json_decode((string) ($versionRow['snapshot'] ?? ''), true);
                if (!is_array($snapshot)) {
                    continue;
                }
                // Snapshots come in two shapes: the entry-row shape with field
                // values nested under `data`, and a flattened entry shape where
                // field handles sit at the top level. Handle both.
                $blocksKey = null;
                if (is_array($snapshot['data'] ?? null) && array_key_exists('blocks', $snapshot['data'])) {
                    $blocksKey = ['data', 'blocks'];
                } elseif (array_key_exists('blocks', $snapshot)) {
                    $blocksKey = ['', 'blocks'];
                }
                if ($blocksKey === null) {
                    continue;
                }
                $blocks = $blocksKey[0] === '' ? $snapshot['blocks'] : $snapshot['data']['blocks'];
                if (is_array($blocks)) {
                    $puck = self::convertLegacyBlocksToPuck($blocks);
                    if ($blocksKey[0] === '') {
                        $snapshot['content'] = $puck;
                    } else {
                        $snapshot['data']['content'] = $puck;
                    }
                }
                if ($blocksKey[0] === '') {
                    unset($snapshot['blocks']);
                } else {
                    unset($snapshot['data']['blocks']);
                }
                $updateVersion->execute([
                    json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    (string) $versionRow['id'],
                ]);
            }
        }
    }

    public function down(): void
    {
        $collections = $this->connection->prefixTable('collections');
        $fields = $this->connection->prefixTable('fields');
        $pdo = $this->connection->getPdo();
        $now = date('Y-m-d H:i:s.v');

        $stmt = $pdo->prepare("SELECT `id` FROM `{$collections}` WHERE `handle` = 'pages' LIMIT 1");
        $stmt->execute();
        $pagesColId = $stmt->fetchColumn();

        if ($pagesColId === false) {
            return;
        }

        // Remove the puck field row and restore the legacy blocks row.
        // Converted data is intentionally left as-is (best-effort reverse).
        $delete = $pdo->prepare("DELETE FROM `{$fields}` WHERE `collection_id` = ? AND `handle` = 'content'");
        $delete->execute([$pagesColId]);

        $exists = $pdo->prepare("SELECT `id` FROM `{$fields}` WHERE `collection_id` = ? AND `handle` = 'blocks'");
        $exists->execute([$pagesColId]);
        if ($exists->fetchColumn() === false) {
            $insert = $pdo->prepare(
                "INSERT INTO `{$fields}` (
                    `id`, `collection_id`, `handle`, `label`, `type`, `config`,
                    `required`, `is_unique`, `is_filterable`, `is_sortable`,
                    `is_searchable`, `show_in_list`, `default_value`, `help_text`,
                    `placeholder`, `group_key`, `position`, `width`,
                    `validation`, `conditional`, `storage`, `column_name`,
                    `created_at`, `updated_at`
                ) VALUES (?, ?, 'blocks', 'Page Blocks', 'blocks', '{}', 0, 0, 0, 0, 0, 0, NULL, NULL, NULL, NULL, 0, 'full', NULL, NULL, 'json', NULL, ?, ?)"
            );
            // Order: id, collection_id, created_at, updated_at
            $insert->execute([Ulid::generate(), $pagesColId, $now, $now]);
        }
    }

    /**
     * Deterministic conversion of a legacy blocks array into PuckData.
     *
     * Legacy shapes handled: `{type, ...props}` (file-based seed style) and
     * `{type, data: {...}}` (old admin style). Unknown block types are dropped.
     *
     * @param array<int, mixed> $blocks
     * @return array{content: list<array<string, mixed>>, root: array{props: array<never, never>}}
     */
    public static function convertLegacyBlocksToPuck(array $blocks): array
    {
        $content = [];
        $counter = 0;

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $type = $block['type'] ?? null;
            if (!is_string($type) || trim($type) === '') {
                continue;
            }

            // Old admin-style blocks nest the props under a `data` key.
            $props = isset($block['data']) && is_array($block['data']) ? $block['data'] : $block;

            $component = match ($type) {
                // Field names drifted across the block system's lifetime (e.g.
                // the demo seeders used title/subtitle for hero) — alias the
                // known historical names onto the Puck props.
                'hero' => [
                    'type' => 'Hero',
                    'props' => [
                        'id' => 'Hero-mig' . ++$counter,
                        'heading' => self::stringOrNull(self::pick($props, ['heading', 'title'])),
                        'text' => self::stringOrNull(self::pick($props, ['text', 'subtitle'])),
                        'ctaLabel' => self::stringOrNull(self::pick($props, ['cta_label', 'ctaLabel', 'button_label'])),
                        'ctaUrl' => self::stringOrNull(self::pick($props, ['cta_url', 'ctaUrl', 'button_url'])),
                    ],
                ],
                'text' => [
                    'type' => 'RichText',
                    'props' => [
                        'id' => 'RichText-mig' . ++$counter,
                        'html' => self::stringOrNull(self::pick($props, ['content', 'text', 'body', 'html'])),
                    ],
                ],
                'callout' => [
                    'type' => 'Callout',
                    'props' => [
                        'id' => 'Callout-mig' . ++$counter,
                        'variant' => self::stringOrNull($props['variant'] ?? null),
                        'title' => self::stringOrNull($props['title'] ?? null),
                        'content' => self::stringOrNull(self::pick($props, ['content', 'text', 'body'])),
                    ],
                ],
                'faq' => [
                    'type' => 'Faq',
                    'props' => [
                        'id' => 'Faq-mig' . ++$counter,
                        'items' => is_array($props['items'] ?? null) ? array_values($props['items']) : [],
                    ],
                ],
                default => null,
            };

            if ($component !== null) {
                $content[] = $component;
            }
        }

        return ['content' => $content, 'root' => ['props' => []]];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (string) $value;
        }
        return null;
    }

    /**
     * First non-null-and-non-empty-string value among the given prop names,
     * so historical field-name variants resolve to the same Puck prop.
     *
     * @param array<string, mixed> $props
     * @param list<string> $names
     */
    private static function pick(array $props, array $names): mixed
    {
        foreach ($names as $name) {
            $value = $props[$name] ?? null;
            if ($value !== null && (!is_string($value) || trim($value) !== '')) {
                return $value;
            }
        }
        return null;
    }
}
