<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Content\Cache\PublicResponseCache;
use BsCms\Content\EntryRepository;
use BsCms\Content\Query\QueryParser;
use BsCms\Database\Connection;
use BsCms\Schema\CollectionRegistry;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class PublicEntriesController extends BaseController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CollectionRegistry $registry,
        private readonly EntryRepository $repository,
        private readonly QueryParser $queryParser,
        private readonly PublicResponseCache $cache,
        private readonly ?\BsCms\Seo\SeoResolver $seoResolver = null
    ) {
    }

    /**
     * @param array<string, mixed> $args
     */
    public function list(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        if (!$this->registry->has($handle)) {
            return $this->problem(404, 'Not Found', "Collection '{$handle}' not found.");
        }

        $cacheKey = 'list:' . $request->getUri()->getPath() . '?' . $request->getUri()->getQuery();
        $ifNoneMatch = $request->getHeaderLine('If-None-Match');

        $cached = $this->cache->get($handle, $cacheKey);
        if ($cached !== null) {
            if ($ifNoneMatch !== '' && trim($ifNoneMatch) === $cached['etag']) {
                return (new Response(304))
                    ->withHeader('ETag', $cached['etag'])
                    ->withHeader('Cache-Control', 'public, max-age=60, stale-while-revalidate=600');
            }

            return (new Response(200, [
                'Content-Type' => 'application/json; charset=utf-8',
                'ETag' => $cached['etag'],
                'Cache-Control' => 'public, max-age=60, stale-while-revalidate=600',
            ], $cached['body']));
        }

        // Build query forcing status = 'published'
        $params = $request->getQueryParams();
        if (!isset($params['filter']) || !is_array($params['filter'])) {
            $params['filter'] = [];
        }
        $params['filter']['status'] = ['eq' => 'published'];
        $requestWithFilter = $request->withQueryParams($params);

        try {
            $collection = $this->registry->get($handle);
            $queryOptions = $this->queryParser->parse($requestWithFilter, $collection);
            $result = $this->repository->query($handle, $queryOptions);

            // Present entries through EntryPresenter::forPublic to strip internal columns
            $publicEntries = [];
            foreach ($result['data'] as $entry) {
                $publicEntries[] = \BsCms\Content\Presenters\EntryPresenter::forPublic($entry, $collection);
            }
            $result['data'] = $publicEntries;

            $body = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $etag = '"' . hash('sha256', $body) . '"';

            $this->cache->set($handle, $cacheKey, 200, ['Content-Type' => 'application/json'], $body, $etag, 60);

            if ($ifNoneMatch !== '' && trim($ifNoneMatch) === $etag) {
                return (new Response(304))
                    ->withHeader('ETag', $etag)
                    ->withHeader('Cache-Control', 'public, max-age=60, stale-while-revalidate=600');
            }

            return (new Response(200, [
                'Content-Type' => 'application/json; charset=utf-8',
                'ETag' => $etag,
                'Cache-Control' => 'public, max-age=60, stale-while-revalidate=600',
            ], $body));
        } catch (\BsCms\Content\Query\QueryValidationException $e) {
            return $this->problem(422, 'Query Validation Error', $e->getMessage(), null, $e->getErrors());
        } catch (\Throwable $e) {
            $this->logServerError($request, $e, 'PublicEntriesController::list');
            $msg = ($e instanceof \PDOException) ? 'A database error occurred while querying entries.' : $e->getMessage();
            return $this->problem(500, 'Query Error', $msg);
        }
    }

    /**
     * @param array<string, mixed> $args
     */
    public function bySlug(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $slug = (string) ($args['slug'] ?? $request->getAttribute('slug', ''));

        if (!$this->registry->has($handle)) {
            return $this->problem(404, 'Not Found', "Collection '{$handle}' not found.");
        }

        $cacheKey = 'slug:' . $slug;
        $ifNoneMatch = $request->getHeaderLine('If-None-Match');

        $cached = $this->cache->get($handle, $cacheKey);
        if ($cached !== null) {
            if ($ifNoneMatch !== '' && trim($ifNoneMatch) === $cached['etag']) {
                return (new Response(304))
                    ->withHeader('ETag', $cached['etag'])
                    ->withHeader('Cache-Control', 'public, max-age=60, stale-while-revalidate=600');
            }

            return (new Response(200, [
                'Content-Type' => 'application/json; charset=utf-8',
                'ETag' => $cached['etag'],
                'Cache-Control' => 'public, max-age=60, stale-while-revalidate=600',
            ], $cached['body']));
        }

        $prefix = $this->connection->getPrefix();
        $tableName = $prefix . 'c_' . $handle;

        $stmt = $this->connection->execute(
            "SELECT * FROM `{$tableName}` WHERE `slug` = ? AND `status` = 'published' AND `deleted_at` IS NULL",
            [$slug]
        );
        $entry = $stmt->fetch();
        if (!$entry) {
            return $this->problem(404, 'Not Found', "Published entry with slug '{$slug}' not found in '{$handle}'.");
        }

        if (is_string($entry['data'])) {
            $entry['data'] = json_decode($entry['data'], true) ?? [];
        }
        if (is_string($entry['seo'])) {
            $entry['seo'] = json_decode($entry['seo'], true);
        }

        $collection = $this->registry->get($handle);
        $publicEntry = \BsCms\Content\Presenters\EntryPresenter::forPublic($entry, $collection);

        if ($this->seoResolver !== null) {
            $seoRes = $this->seoResolver->resolveForEntity($entry, $handle === 'posts' ? 'post' : 'page');
            $publicEntry['seo_resolved'] = $seoRes['seo_resolved'];
            $publicEntry['structured_data'] = $seoRes['structured_data'];
        }

        $body = json_encode(['data' => $publicEntry], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $etag = '"' . hash('sha256', $body) . '"';

        $this->cache->set($handle, $cacheKey, 200, ['Content-Type' => 'application/json'], $body, $etag, 60);

        if ($ifNoneMatch !== '' && trim($ifNoneMatch) === $etag) {
            return (new Response(304))
                ->withHeader('ETag', $etag)
                ->withHeader('Cache-Control', 'public, max-age=60, stale-while-revalidate=600');
        }

        return (new Response(200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'ETag' => $etag,
            'Cache-Control' => 'public, max-age=60, stale-while-revalidate=600',
        ], $body));
    }

    /**
     * Public site metadata and navigation endpoint.
     * GET /api/v1/public/site
     */
    public function site(ServerRequestInterface $request): ResponseInterface
    {
        $prefix = $this->connection->getPrefix();

        // 1. Resolve site settings
        $siteName = 'BS CMS';
        $tagline = '';
        $description = '';
        $defaultLanguage = 'en';
        $canonicalUrl = '';
        $environment = 'production';

        try {
            $stmt = $this->connection->execute("SELECT `data` FROM `{$prefix}c_site_settings` LIMIT 1");
            $siteRow = $stmt->fetch();
            if ($siteRow && !empty($siteRow['data'])) {
                $siteData = is_string($siteRow['data']) ? json_decode($siteRow['data'], true) : $siteRow['data'];
                if (is_array($siteData)) {
                    $siteName = (string) ($siteData['site_name'] ?? $siteName);
                    $tagline = (string) ($siteData['tagline'] ?? '');
                    $description = (string) ($siteData['description'] ?? $tagline);
                    $defaultLanguage = (string) ($siteData['default_language'] ?? 'en');
                    $canonicalUrl = (string) ($siteData['canonical_url'] ?? '');
                    $environment = (string) ($siteData['environment'] ?? 'production');
                }
            }
        } catch (\Throwable) {
            // Fallback to defaults if table doesn't exist yet
        }

        // 2. Resolve navigation
        $navItems = [];
        try {
            // Check navigation singleton first
            $stmtNav = $this->connection->execute("SELECT `data` FROM `{$prefix}c_navigation` LIMIT 1");
            $navRow = $stmtNav->fetch();
            if ($navRow && !empty($navRow['data'])) {
                $navData = is_string($navRow['data']) ? json_decode($navRow['data'], true) : $navRow['data'];
                if (is_array($navData) && !empty($navData['items']) && is_array($navData['items'])) {
                    foreach ($navData['items'] as $item) {
                        if (is_array($item) && !empty($item['label']) && !empty($item['url'])) {
                            $navItems[] = [
                                'label' => (string) $item['label'],
                                'url' => (string) $item['url'],
                                'target' => !empty($item['target']) ? '_blank' : null,
                            ];
                        }
                    }
                }
            }
        } catch (\Throwable) {
            // Navigation singleton not available
        }

        // If navigation singleton has no items, derive from published pages
        if (empty($navItems)) {
            // Always include Home and Blog
            $navItems[] = ['label' => 'Home', 'url' => '/', 'target' => null];
            $navItems[] = ['label' => 'Blog', 'url' => '/blog/', 'target' => null];

            try {
                $stmtPages = $this->connection->execute(
                    "SELECT `title`, `slug`, `data` FROM `{$prefix}c_pages` 
                     WHERE `status` = 'published' AND `deleted_at` IS NULL 
                     ORDER BY `position` ASC, `created_at` ASC"
                );
                $pages = $stmtPages->fetchAll();
                foreach ($pages as $p) {
                    $pData = is_string($p['data'] ?? null) ? json_decode($p['data'], true) : ($p['data'] ?? []);
                    $showInNav = $pData['show_in_nav'] ?? true;
                    // Do not duplicate home in nav if it has slug 'home' or 'index'
                    $slug = (string) ($p['slug'] ?? '');
                    if ($showInNav && !in_array($slug, ['home', 'index', ''], true)) {
                        $label = !empty($pData['nav_label']) ? (string) $pData['nav_label'] : (string) ($p['title'] ?? $slug);
                        $navItems[] = [
                            'label' => $label,
                            'url' => "/{$slug}/",
                            'target' => null,
                        ];
                    }
                }
            } catch (\Throwable) {
                // Ignore if pages table does not exist
            }
        }

        $body = [
            'site_name' => $siteName,
            'tagline' => $tagline,
            'description' => $description,
            'default_language' => $defaultLanguage,
            'canonical_url' => $canonicalUrl,
            'environment' => $environment,
            'navigation' => $navItems,
        ];

        return $this->json($body);
    }
}
