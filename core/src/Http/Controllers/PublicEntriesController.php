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
        private readonly PublicResponseCache $cache
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
        } catch (\Throwable $e) {
            return $this->problem(400, 'Query Error', $e->getMessage());
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
}
