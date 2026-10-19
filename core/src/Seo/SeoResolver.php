<?php

declare(strict_types=1);

namespace BsCms\Seo;

use BsCms\Content\EntryRepository;
use BsCms\Database\Connection;
use BsCms\Media\MediaRepository;
use BsCms\Taxonomy\TaxonomyRepository;
use BsCms\Taxonomy\TermRepository;
use PDO;

final class SeoResolver
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ?MediaRepository $media = null,
        private readonly ?TaxonomyRepository $taxonomies = null,
        private readonly ?TermRepository $terms = null,
        private readonly string $siteUrl = 'http://localhost'
    ) {
    }

    /**
     * Resolve SEO metadata and Structured Data for any entity array.
     *
     * @param array<string, mixed> $entity
     * @param array<string, mixed> $siteConfig
     * @return array{
     *     seo_resolved: array<string, mixed>,
     *     structured_data: array<string, mixed>
     * }
     */
    public function resolveForEntity(array $entity, string $type = 'post', array $siteConfig = []): array
    {
        $siteUrl = rtrim((string) ($siteConfig['site_url'] ?? $this->siteUrl), '/');
        $siteName = (string) ($siteConfig['site_name'] ?? 'BS CMS');
        $siteDesc = (string) ($siteConfig['site_description'] ?? '');

        $title = (string) ($entity['seo']['title'] ?? $entity['title'] ?? $entity['name'] ?? $siteName);
        $desc = (string) ($entity['seo']['description'] ?? $entity['excerpt'] ?? $entity['summary'] ?? $entity['description'] ?? $siteDesc);

        // Path & Canonical
        $slug = (string) ($entity['slug'] ?? '');
        $path = (string) ($entity['path'] ?? ($type === 'post' ? "/posts/{$slug}" : "/{$slug}"));
        $canonical = (string) ($entity['seo']['canonical'] ?? '');
        if ($canonical === '' || !preg_match('#^https?://#i', $canonical)) {
            $canonical = $siteUrl . '/' . ltrim($path, '/');
        }

        // Image resolution
        $imageUrl = null;
        $imageMediaId = (string) ($entity['seo']['og_image_media_id'] ?? $entity['featured_image'] ?? $entity['image_media_id'] ?? '');
        if ($imageMediaId !== '' && $this->media !== null) {
            $mediaItem = $this->media->findById($imageMediaId);
            if ($mediaItem !== null && !empty($mediaItem['url'])) {
                $rawUrl = (string) $mediaItem['url'];
                $imageUrl = preg_match('#^https?://#i', $rawUrl) ? $rawUrl : $siteUrl . '/' . ltrim($rawUrl, '/');
            }
        }

        // Robots directives
        $robots = [
            'noindex' => !empty($entity['seo']['noindex']) || ($entity['status'] ?? 'published') !== 'published',
            'nofollow' => !empty($entity['seo']['nofollow']),
            'nosnippet' => !empty($entity['seo']['nosnippet']),
            'noarchive' => !empty($entity['seo']['noarchive']),
            'max_snippet' => isset($entity['seo']['max_snippet']) ? (int) $entity['seo']['max_snippet'] : null,
            'max_image_preview' => (string) ($entity['seo']['max_image_preview'] ?? 'large'),
        ];

        $seoRaw = [
            'title' => $title,
            'description' => $desc,
            'canonical' => $canonical,
            'robots' => $robots,
            'open_graph' => [
                'title' => (string) ($entity['seo']['og_title'] ?? $title),
                'description' => (string) ($entity['seo']['og_description'] ?? $desc),
                'type' => $type === 'post' ? 'article' : 'website',
                'url' => $canonical,
                'image' => $imageUrl,
                'site_name' => $siteName,
            ],
            'twitter' => [
                'card' => $imageUrl !== null ? 'summary_large_image' : 'summary',
                'title' => (string) ($entity['seo']['twitter_title'] ?? $title),
                'description' => (string) ($entity['seo']['twitter_description'] ?? $desc),
                'image' => $imageUrl,
            ],
        ];

        $seoResolved = SeoPresenter::present($seoRaw);

        // Extract FAQ items if available
        $faq = [];
        $rawFaq = $entity['faq'] ?? ($entity['data']['faq'] ?? null);
        if (!empty($rawFaq) && is_array($rawFaq)) {
            foreach ($rawFaq as $f) {
                if (isset($f['question'], $f['answer'])) {
                    $faq[] = [
                        'question' => (string) $f['question'],
                        'answer' => is_string($f['answer']) ? $f['answer'] : (string) json_encode($f['answer']),
                    ];
                }
            }
        }

        // Breadcrumbs
        $breadcrumbs = [
            ['name' => 'Home', 'url' => $siteUrl . '/'],
        ];
        if ($type === 'post') {
            $breadcrumbs[] = ['name' => 'Posts', 'url' => "{$siteUrl}/posts"];
        } elseif ($type === 'taxonomy') {
            $breadcrumbs[] = ['name' => 'Categories', 'url' => "{$siteUrl}/categories"];
        }
        $breadcrumbs[] = ['name' => $title, 'url' => $canonical];

        $structuredData = StructuredDataBuilder::build(
            site: [
                'site_name' => $siteName,
                'site_url' => $siteUrl,
                'logo_url' => $siteConfig['logo_url'] ?? null,
                'organization_name' => $siteConfig['organization_name'] ?? $siteName,
                'same_as' => $siteConfig['same_as'] ?? [],
            ],
            entity: [
                'type' => $type,
                'title' => $title,
                'description' => $desc,
                'canonical' => $canonical,
                'published_at' => isset($entity['published_at']) ? (string) $entity['published_at'] : null,
                'updated_at' => isset($entity['updated_at']) ? (string) $entity['updated_at'] : null,
                'author_name' => isset($entity['author_id']) ? 'Author' : null,
                'image_url' => $imageUrl,
                'breadcrumbs' => $breadcrumbs,
                'faq' => $faq,
            ]
        );

        return [
            'seo_resolved' => $seoResolved,
            'structured_data' => $structuredData,
        ];
    }

    /**
     * Resolve SEO and Structured Data by public route path.
     *
     * @param array<string, mixed> $siteConfig
     * @return array{
     *     entity_type: string,
     *     entity_id: string,
     *     entity: array<string, mixed>,
     *     seo_resolved: array<string, mixed>,
     *     structured_data: array<string, mixed>
     * }|null
     */
    public function resolvePath(string $rawPath, array $siteConfig = []): ?array
    {
        $path = '/' . trim($rawPath, '/');
        $parts = explode('/', trim($path, '/'));

        // 1. Root / homepage check
        if ($path === '/' || $parts[0] === '') {
            $home = [
                'title' => $siteConfig['site_name'] ?? 'Home',
                'description' => $siteConfig['site_description'] ?? 'Home page',
                'canonical' => $this->siteUrl . '/',
                'path' => '/',
                'status' => 'published',
            ];
            $res = $this->resolveForEntity($home, 'page', $siteConfig);
            return [
                'entity_type' => 'page',
                'entity_id' => 'home',
                'entity' => $home,
                'seo_resolved' => $res['seo_resolved'],
                'structured_data' => $res['structured_data'],
            ];
        }

        // 2. Posts check: /posts/{slug} or single post
        if ($parts[0] === 'posts' && isset($parts[1])) {
            $slug = $parts[1];
            $post = $this->findEntryBySlug('posts', $slug);
            if ($post !== null) {
                $res = $this->resolveForEntity($post, 'post', $siteConfig);
                return [
                    'entity_type' => 'post',
                    'entity_id' => (string) $post['id'],
                    'entity' => $post,
                    'seo_resolved' => $res['seo_resolved'],
                    'structured_data' => $res['structured_data'],
                ];
            }
        }

        // 3. Pages check: /{slug}
        $slug = end($parts);
        $page = $this->findEntryBySlug('pages', $slug);
        if ($page !== null) {
            $res = $this->resolveForEntity($page, 'page', $siteConfig);
            return [
                'entity_type' => 'page',
                'entity_id' => (string) $page['id'],
                'entity' => $page,
                'seo_resolved' => $res['seo_resolved'],
                'structured_data' => $res['structured_data'],
            ];
        }

        // 4. Term check: /categories/{slug} or /tags/{slug}
        if ($this->taxonomies !== null && count($parts) >= 2) {
            $taxHandle = $parts[0];
            $tax = $this->taxonomies->findByHandle($taxHandle);
            if ($tax !== null) {
                $termSlug = end($parts);
                $term = $this->terms?->findBySlug((string) $tax['id'], $termSlug);
                if ($term !== null) {
                    $term['name'] = $term['name'] ?? $termSlug;
                    $term['path'] = $path;
                    $res = $this->resolveForEntity($term, 'taxonomy', $siteConfig);
                    return [
                        'entity_type' => 'term',
                        'entity_id' => (string) $term['id'],
                        'entity' => $term,
                        'seo_resolved' => $res['seo_resolved'],
                        'structured_data' => $res['structured_data'],
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Find published entry by slug in collection table.
     *
     * @return array<string, mixed>|null
     */
    private function findEntryBySlug(string $collection, string $slug): ?array
    {
        $table = $this->connection->prefixTable('c_' . $collection);
        $check = $this->connection->execute("SHOW TABLES LIKE '{$table}'");
        if (!$check->fetch()) {
            return null;
        }

        $stmt = $this->connection->execute(
            "SELECT * FROM `{$table}` WHERE `slug` = ? AND `status` = 'published' AND `deleted_at` IS NULL LIMIT 1",
            [$slug]
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        // Decode JSON fields if needed
        foreach ($row as $k => $v) {
            if (is_string($v) && (str_starts_with($v, '{') || str_starts_with($v, '['))) {
                $decoded = json_decode($v, true);
                if (is_array($decoded)) {
                    $row[$k] = $decoded;
                }
            }
        }

        return $row;
    }
}
