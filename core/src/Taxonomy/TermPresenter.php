<?php

declare(strict_types=1);

namespace BsCms\Taxonomy;

final class TermPresenter
{
    /**
     * Canonical public representation of a taxonomy term.
     * Contains only safe public attributes with stable keys.
     *
     * @param array<string, mixed> $term
     * @return array<string, mixed>
     */
    public static function forPublic(array $term): array
    {
        return [
            'id' => (string) $term['id'],
            'taxonomy_id' => (string) $term['taxonomy_id'],
            'parent_id' => $term['parent_id'] !== null ? (string) $term['parent_id'] : null,
            'name' => (string) $term['name'],
            'slug' => (string) $term['slug'],
            'slug_path' => (string) $term['slug_path'],
            'description' => $term['description'],
            'depth' => (int) $term['depth'],
            'position' => (int) $term['position'],
            'image_media_id' => $term['image_media_id'] ?? null,
            'color' => $term['color'] ?? null,
            'icon' => $term['icon'] ?? null,
            'entry_count' => (int) ($term['entry_count'] ?? 0),
            'seo' => $term['seo'] ?? null,
        ];
    }
}
