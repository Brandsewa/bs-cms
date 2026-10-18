<?php

declare(strict_types=1);

namespace BsCms\Content;

use BsCms\Database\Connection;

final class SlugService
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Generate an ASCII, lowercase, hyphenated slug (max 120 chars) from a title or input string.
     */
    public function slugify(string $text, int $maxLength = 120): string
    {
        // Transliterate non-ASCII characters to ASCII if transliterator is available
        if (function_exists('transliterator_transliterate')) {
            $trans = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
            if ($trans !== false) {
                $text = $trans;
            } else {
                $text = strtolower($text);
            }
        } else {
            $text = strtolower($text);
        }

        // Replace non-alphanumeric characters with hyphens
        $slug = (string) preg_replace('/[^a-z0-9]+/i', '-', $text);
        $slug = trim($slug, '-');

        if ($slug === '') {
            $slug = 'item';
        }

        if (strlen($slug) > $maxLength) {
            $slug = substr($slug, 0, $maxLength);
            $slug = rtrim($slug, '-');
        }

        return $slug;
    }

    /**
     * Ensure slug is unique within a collection and locale by appending numeric suffixes if needed.
     */
    public function generateUniqueSlug(
        string $collectionHandle,
        string $baseText,
        string $locale = 'en',
        ?string $currentEntryId = null
    ): string {
        $baseSlug = $this->slugify($baseText);
        $prefix = $this->connection->getPrefix();
        $tableName = $prefix . 'c_' . $collectionHandle;

        $slug = $baseSlug;
        $counter = 1;

        while (true) {
            $sql = "SELECT `id` FROM `{$tableName}` WHERE `slug` = ? AND `locale` = ?";
            $params = [$slug, $locale];

            if ($currentEntryId !== null) {
                $sql .= " AND `id` != ?";
                $params[] = $currentEntryId;
            }

            $stmt = $this->connection->execute($sql, $params);
            $existing = $stmt->fetch();

            if (!$existing) {
                return $slug;
            }

            ++$counter;
            $suffix = '-' . $counter;
            $truncatedBase = substr($baseSlug, 0, 120 - strlen($suffix));
            $slug = rtrim($truncatedBase, '-') . $suffix;
        }
    }
}
