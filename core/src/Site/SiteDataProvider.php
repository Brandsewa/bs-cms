<?php

declare(strict_types=1);

namespace BsCms\Site;

use BsCms\Database\Connection;

/**
 * Site-level data for the on-the-fly renderer: settings singleton, header
 * navigation, branding tokens (media ids resolved to URLs). Mirrors the
 * payload of GET /api/v1/public/site but reads the DB directly.
 */
final class SiteDataProvider
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array<string, mixed> */
    public function get(): array
    {
        $prefix = $this->connection->getPrefix();

        $siteName = 'BS CMS';
        $tagline = '';
        $description = '';
        $defaultLanguage = 'en';
        $canonicalUrl = '';

        try {
            $row = $this->connection->execute("SELECT `data` FROM `{$prefix}c_site_settings` LIMIT 1")->fetch();
            if ($row && !empty($row['data'])) {
                $data = is_string($row['data']) ? json_decode($row['data'], true) : $row['data'];
                if (is_array($data)) {
                    $siteName = (string) ($data['site_name'] ?? $siteName);
                    $tagline = (string) ($data['tagline'] ?? '');
                    $description = (string) ($data['description'] ?? $tagline);
                    $defaultLanguage = (string) ($data['default_language'] ?? 'en');
                    $canonicalUrl = (string) ($data['canonical_base_url'] ?? $data['canonical_url'] ?? '');
                }
            }
        } catch (\Throwable) {
        }

        $navigation = $this->navigationItems($prefix);

        $branding = null;
        try {
            $row = $this->connection->execute("SELECT `data` FROM `{$prefix}c_branding` WHERE `deleted_at` IS NULL LIMIT 1")->fetch();
            if ($row && !empty($row['data'])) {
                $data = is_string($row['data']) ? json_decode($row['data'], true) : $row['data'];
                if (is_array($data)) {
                    $branding = [];
                    foreach ($data as $key => $value) {
                        if (in_array($key, ['logo', 'logo_dark', 'favicon'], true)) {
                            $branding[$key . '_url'] = $this->mediaUrl($prefix, (string) $value);
                        } elseif (is_scalar($value)) {
                            $branding[(string) $key] = (string) $value;
                        }
                    }
                }
            }
        } catch (\Throwable) {
        }

        return [
            'site_name' => $siteName,
            'tagline' => $tagline,
            'description' => $description,
            'default_language' => $defaultLanguage,
            'canonical_url' => $canonicalUrl,
            'navigation' => $navigation,
            'branding' => $branding,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function navigationItems(string $prefix): array
    {
        $items = [];
        try {
            $row = $this->connection->execute("SELECT `data` FROM `{$prefix}c_navigation` LIMIT 1")->fetch();
            if ($row && !empty($row['data'])) {
                $data = is_string($row['data']) ? json_decode($row['data'], true) : $row['data'];
                if (is_array($data) && !empty($data['items']) && is_array($data['items'])) {
                    $rowsById = [];
                    $order = [];
                    foreach ($data['items'] as $i => $item) {
                        if (!is_array($item) || empty($item['label']) || empty($item['url'])) {
                            continue;
                        }
                        if (($item['menu'] ?? 'header') === 'footer') {
                            continue;
                        }
                        $id = (string) ($item['id'] ?? ('row_' . $i));
                        $rowsById[$id] = [
                            'label' => (string) $item['label'],
                            'url' => (string) $item['url'],
                            'target' => !empty($item['target']) ? '_blank' : null,
                            'parent_id' => isset($item['parent_id']) ? (string) $item['parent_id'] : null,
                        ];
                        $order[] = $id;
                    }
                    $childrenOf = [];
                    $topLevel = [];
                    foreach ($order as $id) {
                        $parentId = $rowsById[$id]['parent_id'];
                        if ($parentId !== null && isset($rowsById[$parentId])) {
                            $childrenOf[$parentId][] = $id;
                        } else {
                            $topLevel[] = $id;
                        }
                    }
                    $compose = function (string $id) use (&$compose, $rowsById, $childrenOf): array {
                        $row = $rowsById[$id];
                        unset($row['parent_id']);
                        $kids = [];
                        foreach ($childrenOf[$id] ?? [] as $childId) {
                            $kids[] = $compose($childId);
                        }
                        if ($kids !== []) {
                            $row['children'] = $kids;
                        }

                        return $row;
                    };
                    foreach ($topLevel as $id) {
                        $items[] = $compose($id);
                    }
                }
            }
        } catch (\Throwable) {
        }

        if ($items === []) {
            $items[] = ['label' => 'Home', 'url' => '/', 'target' => null];
            try {
                $rows = $this->connection->execute(
                    "SELECT `title`, `slug`, `data` FROM `{$prefix}c_pages`
                     WHERE `status` = 'published' AND `deleted_at` IS NULL
                     ORDER BY `position` ASC, `created_at` ASC"
                )->fetchAll();
                foreach ($rows as $p) {
                    $pData = is_string($p['data'] ?? null) ? json_decode($p['data'], true) : ($p['data'] ?? []);
                    if (!is_array($pData)) {
                        $pData = [];
                    }
                    $slug = (string) ($p['slug'] ?? '');
                    $showInNav = $pData['show_in_nav'] ?? true;
                    if ($showInNav && !in_array($slug, ['home', 'index', ''], true)) {
                        $items[] = [
                            'label' => !empty($pData['nav_label']) ? (string) $pData['nav_label'] : (string) ($p['title'] ?? $slug),
                            'url' => "/{$slug}/",
                            'target' => null,
                        ];
                    }
                }
            } catch (\Throwable) {
            }
        }

        return $items;
    }

    private function mediaUrl(string $prefix, string $mediaId): ?string
    {
        $mediaId = trim($mediaId);
        if ($mediaId === '') {
            return null;
        }
        try {
            $row = $this->connection->execute(
                "SELECT `storage_path` FROM `{$prefix}media` WHERE `id` = ? AND `deleted_at` IS NULL LIMIT 1",
                [$mediaId]
            )->fetch();

            return isset($row['storage_path']) ? '/' . ltrim((string) $row['storage_path'], '/') : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
