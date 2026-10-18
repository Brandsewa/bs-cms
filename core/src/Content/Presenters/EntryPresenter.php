<?php

declare(strict_types=1);

namespace BsCms\Content\Presenters;

use BsCms\Auth\Actor;
use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;

final class EntryPresenter
{
    /**
     * Present entry for public unauthenticated consumer.
     * Only published content fields + id, slug, title, published_at, updated_at (+ resolved relations).
     * Internal audit fields (created_by, updated_by, author_id, deleted_at, search_text, position) and status are omitted.
     *
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $collection
     * @return array<string, mixed>
     */
    public static function forPublic(array $entry, array $collection): array
    {
        $presented = [
            'id' => (string) ($entry['id'] ?? ''),
            'slug' => (string) ($entry['slug'] ?? ''),
            'title' => (string) ($entry['title'] ?? ''),
            'published_at' => $entry['published_at'] ?? null,
            'updated_at' => $entry['updated_at'] ?? null,
        ];

        if (isset($entry['locale'])) {
            $presented['locale'] = $entry['locale'];
        }

        if (isset($entry['seo']) && is_array($entry['seo'])) {
            $presented['seo'] = $entry['seo'];
        }

        // Include content fields from entry['data']
        $data = (array) ($entry['data'] ?? []);
        $fields = (array) ($collection['fields'] ?? []);

        $converter = new \BsCms\Document\DocumentConverter();
        foreach ($fields as $handle => $def) {
            $h = (string) $handle;
            if (array_key_exists($h, $data)) {
                $presented[$h] = $data[$h];
                if (($def['type'] ?? '') === 'richtext' && is_array($data[$h])) {
                    $presented[$h . '_markdown'] = $converter->documentToMarkdown($data[$h]);
                }
            }
        }

        return $presented;
    }

    /**
     * Present entry for authenticated CMS user.
     * Includes content fields + read-permitted fields + id, slug, title, status, version, published_at,
     * scheduled_at, created_at, updated_at, author_id.
     * Strips internal columns: search_text, created_by, updated_by, deleted_at (unless viewing trash).
     *
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $collection
     * @param Actor $actor
     * @param Authorizer $authorizer
     * @param bool $viewingTrash
     * @return array<string, mixed>
     */
    public static function forAuthenticated(
        array $entry,
        array $collection,
        Actor $actor,
        Authorizer $authorizer,
        bool $viewingTrash = false
    ): array {
        // Strip sensitive internal columns
        $sanitized = $entry;
        unset($sanitized['search_text'], $sanitized['created_by'], $sanitized['updated_by']);

        if (!$viewingTrash) {
            unset($sanitized['deleted_at']);
        }

        // Unpack custom fields from data to top-level and eliminate duplicate data blob
        if (isset($sanitized['data']) && is_array($sanitized['data'])) {
            $data = $sanitized['data'];
            unset($sanitized['data']);
            $converter = new \BsCms\Document\DocumentConverter();
            $fields = (array) ($collection['fields'] ?? []);
            foreach ($data as $k => $v) {
                // Do not overwrite core system metadata if colliding
                if (!in_array($k, ['id', 'collection_id', 'locale', 'slug', 'title', 'status', 'parent_id', 'position', 'author_id', 'published_at', 'scheduled_at', 'seo', 'version', 'created_at', 'updated_at', 'deleted_at'], true)) {
                    $sanitized[$k] = $v;
                    if (isset($fields[$k]) && ($fields[$k]['type'] ?? '') === 'richtext' && is_array($v)) {
                        $sanitized[$k . '_markdown'] = $converter->documentToMarkdown($v);
                    }
                }
            }
        }

        // Apply field-level read permissions
        $handle = (string) ($collection['handle'] ?? '');
        return $authorizer->filterFields($actor, new Subject('collection', $handle), $sanitized, 'read');
    }
}
