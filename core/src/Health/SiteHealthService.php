<?php

declare(strict_types=1);

namespace BsCms\Health;

use BsCms\Database\Connection;
use BsCms\Support\Clock;
use PDO;

final class SiteHealthService
{
    private const CHUNK_SIZE = 100;
    private const TOTAL_RULES_CHECKED = 15;

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Run full site health audit.
     *
     * @return array{
     *     summary: array{total: int, errors: int, warnings: int, info: int, status: string},
     *     issues: list<array<string, mixed>>,
     *     rules_checked: int,
     *     scanned_at: string
     * }
     */
    public function scan(): array
    {
        $issues = [];

        // 1. Check SEO Titles & Duplicates
        $this->checkTitles($issues);

        // 2. Check SEO Descriptions & Duplicates
        $this->checkDescriptions($issues);

        // 3. Check Canonicals and Slugs
        $this->checkCanonicalsAndSlugs($issues);

        // 4. Check Image Alt Texts
        $this->checkImageAltTexts($issues);

        // 5. Check Thin Content and Featured Images
        $this->checkContentDepthAndMedia($issues);

        // 6. Check GEO/AEO Factors (Author, Summary, FAQ)
        $this->checkGeoFactors($issues);

        // 7. Check Technical Redirect Loops
        $this->checkRedirectLoops($issues);

        return SiteHealthPresenter::presentReport([
            'issues' => $issues,
            'rules_checked' => self::TOTAL_RULES_CHECKED,
            'scanned_at' => Clock::sql(Clock::now()),
        ]);
    }

    /**
     * Rule: seo.title.missing, seo.title.too_long, seo.title.duplicate
     *
     * @param list<array<string, mixed>> $issues
     */
    private function checkTitles(array &$issues): void
    {
        $collections = ['posts', 'pages'];
        $titleCounts = [];

        foreach ($collections as $col) {
            $table = $this->connection->prefixTable('c_' . $col);
            if (!$this->tableExists($table)) {
                continue;
            }

            $offset = 0;
            while (true) {
                $stmt = $this->connection->execute(
                    "SELECT `id`, `title`, `status` FROM `{$table}` WHERE `deleted_at` IS NULL LIMIT ? OFFSET ?",
                    [self::CHUNK_SIZE, $offset]
                );
                /** @var list<array{id: string, title: ?string, status: string}> $rows */
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (empty($rows)) {
                    break;
                }

                foreach ($rows as $row) {
                    $id = (string) $row['id'];
                    $title = trim((string) ($row['title'] ?? ''));
                    $status = (string) $row['status'];

                    if ($status === 'published' && $title === '') {
                        $issues[] = [
                            'rule_id' => 'seo.title.missing',
                            'severity' => 'error',
                            'category' => 'seo',
                            'message' => "Published entry in '{$col}' has no title.",
                            'entity_type' => 'entry',
                            'entity_id' => $id,
                            'entity_title' => null,
                            'fix_hint' => 'Provide a title for the entry before publishing.',
                        ];
                    }

                    if ($title !== '') {
                        if (mb_strlen($title) > 70) {
                            $issues[] = [
                                'rule_id' => 'seo.title.too_long',
                                'severity' => 'warn',
                                'category' => 'seo',
                                'message' => "Title '{$title}' is longer than 70 characters (" . mb_strlen($title) . " chars).",
                                'entity_type' => 'entry',
                                'entity_id' => $id,
                                'entity_title' => $title,
                                'fix_hint' => 'Shorten title to 70 characters or fewer for optimal search engine display.',
                            ];
                        }

                        if ($status === 'published') {
                            $lower = mb_strtolower($title);
                            $titleCounts[$lower][] = ['col' => $col, 'id' => $id, 'title' => $title];
                        }
                    }
                }

                $offset += count($rows);
                if (count($rows) < self::CHUNK_SIZE) {
                    break;
                }
            }
        }

        // Detect duplicates
        foreach ($titleCounts as $matches) {
            if (count($matches) > 1) {
                foreach ($matches as $m) {
                    $issues[] = [
                        'rule_id' => 'seo.title.duplicate',
                        'severity' => 'warn',
                        'category' => 'seo',
                        'message' => "Duplicate title '{$m['title']}' shared by multiple published entries.",
                        'entity_type' => 'entry',
                        'entity_id' => $m['id'],
                        'entity_title' => $m['title'],
                        'fix_hint' => 'Ensure titles are unique across all published pages and posts.',
                    ];
                }
            }
        }
    }

    /**
     * Rule: seo.description.missing, seo.description.too_long, seo.description.duplicate
     *
     * @param list<array<string, mixed>> $issues
     */
    private function checkDescriptions(array &$issues): void
    {
        $collections = ['posts', 'pages'];
        $descCounts = [];

        foreach ($collections as $col) {
            $table = $this->connection->prefixTable('c_' . $col);
            if (!$this->tableExists($table)) {
                continue;
            }

            $offset = 0;
            while (true) {
                $stmt = $this->connection->execute(
                    "SELECT `id`, `title`, `status`, `seo`, `data` FROM `{$table}` WHERE `deleted_at` IS NULL LIMIT ? OFFSET ?",
                    [self::CHUNK_SIZE, $offset]
                );
                /** @var list<array{id: string, title: ?string, status: string, seo: ?string, data: ?string}> $rows */
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (empty($rows)) {
                    break;
                }

                foreach ($rows as $row) {
                    $id = (string) $row['id'];
                    $title = (string) ($row['title'] ?? 'Untitled');
                    $status = (string) $row['status'];

                    $seo = is_string($row['seo']) ? (json_decode($row['seo'], true) ?? []) : [];
                    $data = is_string($row['data']) ? (json_decode($row['data'], true) ?? []) : [];

                    $desc = trim((string) ($seo['description'] ?? $data['excerpt'] ?? $data['summary'] ?? ''));

                    if ($status === 'published' && $desc === '') {
                        $issues[] = [
                            'rule_id' => 'seo.description.missing',
                            'severity' => 'warn',
                            'category' => 'seo',
                            'message' => "Published entry '{$title}' is missing a meta description or summary.",
                            'entity_type' => 'entry',
                            'entity_id' => $id,
                            'entity_title' => $title,
                            'fix_hint' => 'Add a 50-160 character description or excerpt to improve search snippets.',
                        ];
                    }

                    if ($desc !== '') {
                        if (mb_strlen($desc) > 160) {
                            $issues[] = [
                                'rule_id' => 'seo.description.too_long',
                                'severity' => 'warn',
                                'category' => 'seo',
                                'message' => "Meta description for '{$title}' is longer than 160 characters (" . mb_strlen($desc) . " chars).",
                                'entity_type' => 'entry',
                                'entity_id' => $id,
                                'entity_title' => $title,
                                'fix_hint' => 'Shorten meta description to 160 characters or fewer.',
                            ];
                        }

                        if ($status === 'published') {
                            $lower = mb_strtolower($desc);
                            $descCounts[$lower][] = ['id' => $id, 'title' => $title];
                        }
                    }
                }

                $offset += count($rows);
                if (count($rows) < self::CHUNK_SIZE) {
                    break;
                }
            }
        }

        foreach ($descCounts as $matches) {
            if (count($matches) > 1) {
                foreach ($matches as $m) {
                    $issues[] = [
                        'rule_id' => 'seo.description.duplicate',
                        'severity' => 'warn',
                        'category' => 'seo',
                        'message' => "Duplicate meta description shared by multiple published entries.",
                        'entity_type' => 'entry',
                        'entity_id' => $m['id'],
                        'entity_title' => $m['title'],
                        'fix_hint' => 'Provide unique meta descriptions for each published entry.',
                    ];
                }
            }
        }
    }

    /**
     * Rule: seo.canonical.invalid, seo.slug.too_long
     *
     * @param list<array<string, mixed>> $issues
     */
    private function checkCanonicalsAndSlugs(array &$issues): void
    {
        $collections = ['posts', 'pages'];

        foreach ($collections as $col) {
            $table = $this->connection->prefixTable('c_' . $col);
            if (!$this->tableExists($table)) {
                continue;
            }

            $stmt = $this->connection->execute(
                "SELECT `id`, `title`, `slug`, `seo` FROM `{$table}` WHERE `deleted_at` IS NULL LIMIT 200"
            );
            /** @var list<array{id: string, title: ?string, slug: ?string, seo: ?string}> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $id = (string) $row['id'];
                $title = (string) ($row['title'] ?? 'Untitled');
                $slug = (string) ($row['slug'] ?? '');
                $seo = is_string($row['seo']) ? (json_decode($row['seo'], true) ?? []) : [];

                if (strlen($slug) > 100) {
                    $issues[] = [
                        'rule_id' => 'seo.slug.too_long',
                        'severity' => 'warn',
                        'category' => 'seo',
                        'message' => "Slug '{$slug}' is longer than 100 characters.",
                        'entity_type' => 'entry',
                        'entity_id' => $id,
                        'entity_title' => $title,
                        'fix_hint' => 'Shorten slug to under 100 characters for cleaner URLs.',
                    ];
                }

                if (!empty($seo['canonical'])) {
                    $can = (string) $seo['canonical'];
                    if (!preg_match('#^https?://#i', $can)) {
                        $issues[] = [
                            'rule_id' => 'seo.canonical.invalid',
                            'severity' => 'error',
                            'category' => 'seo',
                            'message' => "Canonical URL '{$can}' is invalid. Must be an absolute HTTP or HTTPS URL.",
                            'entity_type' => 'entry',
                            'entity_id' => $id,
                            'entity_title' => $title,
                            'fix_hint' => 'Specify a fully-qualified absolute canonical URL starting with https://.',
                        ];
                    }
                }
            }
        }
    }

    /**
     * Rule: seo.image.alt.missing
     *
     * @param list<array<string, mixed>> $issues
     */
    private function checkImageAltTexts(array &$issues): void
    {
        $mediaTable = $this->connection->prefixTable('media');
        if (!$this->tableExists($mediaTable)) {
            return;
        }

        $stmt = $this->connection->execute(
            "SELECT `id`, `original_name`, `alt_text` FROM `{$mediaTable}` WHERE `mime` LIKE 'image/%' AND `deleted_at` IS NULL LIMIT 200"
        );
        /** @var list<array{id: string, original_name: string, alt_text: ?string}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $alt = trim((string) ($row['alt_text'] ?? ''));
            if ($alt === '') {
                $issues[] = [
                    'rule_id' => 'seo.image.alt.missing',
                    'severity' => 'warn',
                    'category' => 'seo',
                    'message' => "Image '{$row['original_name']}' is missing alternative text.",
                    'entity_type' => 'media',
                    'entity_id' => (string) $row['id'],
                    'entity_title' => (string) $row['original_name'],
                    'fix_hint' => 'Add descriptive alt text to the media item for accessibility and image search.',
                ];
            }
        }
    }

    /**
     * Rule: seo.page.thin_content, seo.og.image.missing
     *
     * @param list<array<string, mixed>> $issues
     */
    private function checkContentDepthAndMedia(array &$issues): void
    {
        $table = $this->connection->prefixTable('c_posts');
        if (!$this->tableExists($table)) {
            return;
        }

        $stmt = $this->connection->execute(
            "SELECT `id`, `title`, `data`, `seo` FROM `{$table}` WHERE `status` = 'published' AND `deleted_at` IS NULL LIMIT 100"
        );
        /** @var list<array{id: string, title: ?string, data: ?string, seo: ?string}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $id = (string) $row['id'];
            $title = (string) ($row['title'] ?? 'Untitled');
            $data = is_string($row['data']) ? (json_decode($row['data'], true) ?? []) : [];
            $seo = is_string($row['seo']) ? (json_decode($row['seo'], true) ?? []) : [];

            // Thin content check (< 200 words)
            $wordCount = (int) ($data['word_count'] ?? 0);
            if ($wordCount > 0 && $wordCount < 200) {
                $issues[] = [
                    'rule_id' => 'seo.page.thin_content',
                    'severity' => 'info',
                    'category' => 'seo',
                    'message' => "Published post '{$title}' contains only {$wordCount} words.",
                    'entity_type' => 'entry',
                    'entity_id' => $id,
                    'entity_title' => $title,
                    'fix_hint' => 'Expand article body to provide more thorough and valuable content.',
                ];
            }

            // Missing OG image
            $featuredImage = $data['featured_image'] ?? null;
            $ogImage = $seo['og_image_media_id'] ?? null;
            if (empty($featuredImage) && empty($ogImage)) {
                $issues[] = [
                    'rule_id' => 'seo.og.image.missing',
                    'severity' => 'warn',
                    'category' => 'seo',
                    'message' => "Published post '{$title}' has no featured image or OpenGraph image.",
                    'entity_type' => 'entry',
                    'entity_id' => $id,
                    'entity_title' => $title,
                    'fix_hint' => 'Set a featured image or OpenGraph image to improve social share snippets.',
                ];
            }
        }
    }

    /**
     * Rule: geo.summary.missing, geo.author.missing, geo.date.missing_updated, geo.faq.empty_answers
     *
     * @param list<array<string, mixed>> $issues
     */
    private function checkGeoFactors(array &$issues): void
    {
        $collections = ['posts', 'pages'];

        foreach ($collections as $col) {
            $table = $this->connection->prefixTable('c_' . $col);
            if (!$this->tableExists($table)) {
                continue;
            }

            $stmt = $this->connection->execute(
                "SELECT `id`, `title`, `data`, `author_id`, `updated_at`, `status` FROM `{$table}` WHERE `status` = 'published' AND `deleted_at` IS NULL LIMIT 100"
            );
            /** @var list<array{id: string, title: ?string, data: ?string, author_id: ?string, updated_at: ?string, status: string}> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $id = (string) $row['id'];
                $title = (string) ($row['title'] ?? 'Untitled');
                $data = is_string($row['data']) ? (json_decode($row['data'], true) ?? []) : [];

                // 1. Missing answer summary
                $summary = trim((string) ($data['summary'] ?? ''));
                if ($summary === '') {
                    $issues[] = [
                        'rule_id' => 'geo.summary.missing',
                        'severity' => 'info',
                        'category' => 'geo',
                        'message' => "Entry '{$title}' is missing an Answer Summary.",
                        'entity_type' => 'entry',
                        'entity_id' => $id,
                        'entity_title' => $title,
                        'fix_hint' => 'Add a concise 1-2 sentence Answer Summary to ground AI search and retrieval engines.',
                    ];
                }

                // 2. Missing author (for posts)
                if ($col === 'posts' && empty($row['author_id'])) {
                    $issues[] = [
                        'rule_id' => 'geo.author.missing',
                        'severity' => 'warn',
                        'category' => 'geo',
                        'message' => "Published post '{$title}' has no author assigned.",
                        'entity_type' => 'entry',
                        'entity_id' => $id,
                        'entity_title' => $title,
                        'fix_hint' => 'Assign an author to establish EEAT authority signals.',
                    ];
                }

                // 3. Missing updated_at
                if (empty($row['updated_at'])) {
                    $issues[] = [
                        'rule_id' => 'geo.date.missing_updated',
                        'severity' => 'info',
                        'category' => 'geo',
                        'message' => "Entry '{$title}' has no updated_at timestamp.",
                        'entity_type' => 'entry',
                        'entity_id' => $id,
                        'entity_title' => $title,
                        'fix_hint' => 'Ensure updated_at timestamps are recorded for accurate lastmod indexing.',
                    ];
                }

                // 4. FAQ empty answers check
                if (!empty($data['faq']) && is_array($data['faq'])) {
                    foreach ($data['faq'] as $faqItem) {
                        $q = trim((string) ($faqItem['question'] ?? ''));
                        $a = trim((string) ($faqItem['answer'] ?? ''));
                        if ($q !== '' && $a === '') {
                            $issues[] = [
                                'rule_id' => 'geo.faq.empty_answers',
                                'severity' => 'error',
                                'category' => 'geo',
                                'message' => "FAQ question '{$q}' in '{$title}' has an empty answer.",
                                'entity_type' => 'entry',
                                'entity_id' => $id,
                                'entity_title' => $title,
                                'fix_hint' => 'Provide a clear answer for every FAQ question.',
                            ];
                        }
                    }
                }
            }
        }
    }

    /**
     * Rule: tech.redirect.loop
     *
     * @param list<array<string, mixed>> $issues
     */
    private function checkRedirectLoops(array &$issues): void
    {
        $table = $this->connection->prefixTable('redirects');
        if (!$this->tableExists($table)) {
            return;
        }

        $stmt = $this->connection->execute("SELECT `id`, `from_path`, `to_path` FROM `{$table}` LIMIT 200");
        /** @var list<array{id: string, from_path: string, to_path: string}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $row) {
            $from = '/' . trim($row['from_path'], '/');
            $to = '/' . trim($row['to_path'], '/');
            $map[$from] = ['id' => $row['id'], 'to' => $to];

            if ($from === $to) {
                $issues[] = [
                    'rule_id' => 'tech.redirect.loop',
                    'severity' => 'error',
                    'category' => 'tech',
                    'message' => "Self-loop redirect: '{$from}' redirects to itself.",
                    'entity_type' => 'redirect',
                    'entity_id' => (string) $row['id'],
                    'entity_title' => $from,
                    'fix_hint' => 'Remove or correct this redirect.',
                ];
            }
        }

        // Check 2-hop circular redirects: A -> B and B -> A
        foreach ($map as $from => $data) {
            $target = $data['to'];
            if (isset($map[$target]) && $map[$target]['to'] === $from) {
                $issues[] = [
                    'rule_id' => 'tech.redirect.loop',
                    'severity' => 'error',
                    'category' => 'tech',
                    'message' => "Circular redirect loop between '{$from}' and '{$target}'.",
                    'entity_type' => 'redirect',
                    'entity_id' => (string) $data['id'],
                    'entity_title' => $from,
                    'fix_hint' => 'Break the circular redirect loop.',
                ];
            }
        }
    }

    private function tableExists(string $tableName): bool
    {
        $stmt = $this->connection->execute("SHOW TABLES LIKE '{$tableName}'");
        return (bool) $stmt->fetch();
    }
}
