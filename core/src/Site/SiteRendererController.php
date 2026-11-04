<?php

declare(strict_types=1);

namespace BsCms\Site;

use BsCms\Content\Cache\RenderedPageCache;
use BsCms\Database\Connection;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * WordPress-style on-the-fly site renderer: resolves public URLs to
 * published entries, renders them with the shared layout (Puck/Tiptap
 * ports) and caches the result as HTML. Publish/unpublish/navigation/
 * settings/branding events flush the cache, so "publish = instantly live".
 *
 * Registered as catch-all site routes AFTER all API routes, so /api, /admin,
 * /oauth, /mcp and /health are unaffected. Existing static files (media,
 * admin assets, an Astro dist if deployed) still win before routing.
 */
final class SiteRendererController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RenderedPageCache $cache,
        private readonly SiteDataProvider $siteData,
        private readonly \BsCms\Publishing\PreviewTokenService $previewTokens,
        private readonly \BsCms\Content\EntryRepository $entries,
    ) {
    }

    /**
     * Route: GET /preview?collection=&id=&token= — server-side draft preview.
     * Replaces the static preview island: renders the entry in its current
     * (possibly unpublished) state, noindex and never cached.
     */
    public function preview(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();
        $collectionHandle = (string) ($q['collection'] ?? '');
        $entryId = (string) ($q['id'] ?? '');
        $token = (string) ($q['token'] ?? '');

        if ($token === '' || $collectionHandle === '' || $entryId === '') {
            return $this->noStoreRespond(404, $this->notFoundHtml($request, '/preview'));
        }

        $tokenRow = $this->previewTokens->validateToken($token);
        if ($tokenRow === null) {
            return $this->noStoreRespond(403, PageLayout::render404($this->siteData->get(), '/preview'));
        }

        $entry = $this->entries->find($collectionHandle, $entryId);
        if ($entry === null) {
            return $this->noStoreRespond(404, $this->notFoundHtml($request, '/preview'));
        }

        try {
            $slug = (string) ($entry['slug'] ?? '');
            $path = $collectionHandle === 'posts' ? "/blog/{$slug}" : "/{$slug}";
            $result = match ($collectionHandle) {
                'pages' => $this->renderEntryPage($request, $entry, $path),
                'posts' => $this->renderPost($request, $entry, $path),
                default => $this->renderCustomEntry($request, $entry, $collectionHandle, $path),
            };

            return $this->noStoreRespond((int) $result['status'], (string) $result['body'], (string) ($result['content_type'] ?? 'text/html; charset=UTF-8'));
        } catch (Throwable $e) {
            $site = $this->siteData->get();
            $body = PageLayout::render($site, 'Preview Error | ' . $site['site_name'], '<div style="max-width:800px;margin:3rem auto;padding:2rem;background:#fee2e2;border:1px solid #ef4444;border-radius:8px;color:#991b1b;"><h2 style="margin-top:0">Unable to render preview</h2><p>' . TiptapRenderer::escapeHtml($e->getMessage()) . '</p></div>', '/preview', $entry);
            return $this->noStoreRespond(500, $body);
        }
    }

    private function noStoreRespond(int $status, string $body, string $contentType = 'text/html; charset=UTF-8'): ResponseInterface
    {
        return new Response($status, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ], $body);
    }

    /** Route: GET / — the home page. */
    public function home(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderCached($request, '/', function () use ($request) {
            $entry = $this->findPublished('pages', 'home') ?? $this->findPublished('pages', 'index');
            if ($entry !== null) {
                return $this->renderEntryPage($request, $entry, '/');
            }

            return ['status' => 404, 'body' => $this->notFoundHtml($request, '/')];
        });
    }

    /** Route: GET /sitemap.xml */
    public function sitemap(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond($this->cache->get('/sitemap.xml')
            ?? ['status' => 200, 'body' => $this->sitemapXml(), 'content_type' => 'application/xml; charset=UTF-8'], '/sitemap.xml', true);
    }

    /** Route: GET /rss.xml */
    public function rss(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond($this->cache->get('/rss.xml')
            ?? ['status' => 200, 'body' => $this->rssXml(), 'content_type' => 'application/rss+xml; charset=UTF-8'], '/rss.xml', true);
    }

    /** Route: GET /blog/ — posts archive. */
    public function blogArchive(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderCached($request, '/blog/', fn () => $this->renderArchive($request, 'posts', 'Blog', '/blog/', ['blog-archive', 'posts-archive']));
    }

    /**
     * Route: GET /blog/{slug} — a post.
     *
     * @param array<string, mixed> $args
     */
    public function blogPost(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $slug = (string) ($args['slug'] ?? '');
        return $this->renderCached($request, "/blog/{$slug}", function () use ($request, $slug) {
            $entry = $this->findPublished('posts', $slug);
            if ($entry === null) {
                return ['status' => 404, 'body' => $this->notFoundHtml($request, "/blog/{$slug}")];
            }

            return $this->renderPost($request, $entry, "/blog/{$slug}");
        });
    }

    /**
     * Route: GET /{slug} — catch-all. Resolution order:
     *
     * @param array<string, mixed> $args
     * 1. pages collection (/{slug}/)
     * 2. custom collection archive (/{handle}/) or entry (/{handle}/{slug})
     * 3. redirects, then styled 404.
     */
    public function page(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $path = '/' . trim((string) ($args['slug'] ?? ''), '/');
        $segments = array_values(array_filter(explode('/', trim($path, '/')), fn ($s) => $s !== ''));
        if ($segments === []) {
            return $this->home($request);
        }

        // API / oauth / mcp namespaces must keep returning JSON ProblemDetails,
        // never the HTML site renderer (unknown endpoints included).
        if (in_array($segments[0], ['api', 'oauth', 'mcp'], true)) {
            return \BsCms\Http\ProblemDetails::notFound(
                "The requested URL '{$path}' was not found.",
                (string) ($request->getAttribute('request_id') ?? '')
            )->toResponse();
        }

        // Blog shapes routed through the catch-all (trailing-slash variants).
        if ($segments[0] === 'blog') {
            if (count($segments) === 1) {
                return $this->blogArchive($request);
            }

            return $this->blogPost($request, ['slug' => $segments[1]]);
        }

        return $this->renderCached($request, $path, function () use ($request, $path, $segments) {
            // 1. Page by slug (first segment).
            $page = $this->findPublished('pages', $segments[0]);
            if ($page !== null) {
                return $this->renderEntryPage($request, $page, $path);
            }

            // 2. Custom collections: /{handle}/ archive or /{handle}/{slug}/ entry.
            $collection = $segments[0];
            if ($this->collectionExists($collection)) {
                if (count($segments) === 1) {
                    $label = mb_strtoupper(mb_substr($collection, 0, 1)) . mb_substr($collection, 1);

                    return $this->renderArchive($request, $collection, $label, "/{$collection}/", ["{$collection}-archive"]);
                }
                $entry = $this->findPublished($collection, $segments[1]);
                if ($entry !== null) {
                    return $this->renderCustomEntry($request, $entry, $collection, $path);
                }
            }

            // 3. Redirect rules.
            $redirect = $this->matchRedirect($path);
            if ($redirect !== null) {
                $target = (string) $redirect['to_path'];

                return ['status' => 301, 'body' => '', 'content_type' => 'text/html; charset=UTF-8', 'redirect' => $target];
            }

            return ['status' => 404, 'body' => $this->notFoundHtml($request, $path)];
        });
    }

    // -----------------------------------------------------------------
    // Rendering
    // -----------------------------------------------------------------

    /** @return array{status: int, body: string, content_type?: string, redirect?: string} */
    /**
     * @param array<string, mixed> $entry
     * @return array{status: int, body: string, content_type?: string, redirect?: string}
     */
    private function renderEntryPage(ServerRequestInterface $request, array $entry, string $path): array
    {
        $data = $this->entryData($entry);
        $site = $this->siteData->get();

        // Custom HTML wins (same precedence as the starter).
        $htmlCode = trim((string) ($data['html_code'] ?? ''));
        $puckData = $data['content'] ?? $entry['content'] ?? null;
        if (is_string($puckData)) {
            $decoded = json_decode($puckData, true);
            if (is_array($decoded)) {
                $puckData = $decoded;
            }
        }
        $content = $htmlCode !== ''
            ? $htmlCode
            : (new PuckRenderer())->render($puckData);

        if ($content === '') {
            $heading = (string) ($entry['title'] ?? '');
            $content = "<section class=\"bs-hero\"><div class=\"bs-hero__inner\"><h1 class=\"bs-hero__heading\">"
                . TiptapRenderer::escapeHtml($heading) . '</h1></div></section>';
        }

        $title = (string) ($data['seo']['meta_title'] ?? $data['meta_title'] ?? $entry['title'] ?? '') . ' | ' . $site['site_name'];
        $entry['seo_description'] = (string) ($data['seo']['meta_description'] ?? $data['meta_description'] ?? '');

        return ['status' => 200, 'body' => PageLayout::render($site, $title, $content, $path, $entry), 'content_type' => 'text/html; charset=UTF-8'];
    }

    /** @return array{status: int, body: string, content_type?: string} */
    /**
     * @param array<string, mixed> $entry
     * @return array{status: int, body: string, content_type?: string}
     */
    private function renderPost(ServerRequestInterface $request, array $entry, string $path): array
    {
        $site = $this->siteData->get();
        $data = $this->entryData($entry);

        $templates = $this->loadTemplates();
        $tpl = $templates['blog-single'] ?? $templates['posts-single'] ?? null;

        $title = (string) ($entry['title'] ?? '') . ' | ' . $site['site_name'];
        $entry['seo_description'] = (string) ($data['seo']['meta_description'] ?? $data['excerpt'] ?? '');

        if ($tpl !== null) {
            $ctx = [...$data, ...$entry, 'url' => "{$path}/"];
            $content = $this->renderTemplate($tpl, $ctx);
        } else {
            $bodyData = $data['body'] ?? $entry['body'] ?? null;
            if (is_string($bodyData)) {
                $trimmed = trim($bodyData);
                if (str_starts_with($trimmed, '{')) {
                    $decoded = json_decode($trimmed, true);
                    $bodyHtml = (new TiptapRenderer())->render(is_array($decoded) ? $decoded : null);
                } else {
                    $bodyHtml = $trimmed;
                }
            } elseif (is_array($bodyData)) {
                $bodyHtml = (new TiptapRenderer())->render($bodyData);
            } else {
                $bodyHtml = '';
            }
            $e = fn ($v) => TiptapRenderer::escapeHtml((string) ($v ?? ''));
            $published = (string) ($entry['published_at'] ?? '');
            $meta = $published !== '' ? '<div class="post-meta"><time datetime="' . $e($published) . '">' . $e($this->formatDate($published)) . '</time></div>' : '';
            $content = <<<HTML
  <article class="post-detail">
    <header class="post-header">
      <nav aria-label="Breadcrumb" style="margin-bottom: 1rem;">
        <a href="/blog/" style="text-decoration: none; font-size: 0.9rem;">&larr; Back to all posts</a>
      </nav>
      <h1>{$e($entry['title'] ?? '')}</h1>
      {$meta}
    </header>
    <div class="post-content">
      {$bodyHtml}
    </div>
  </article>
HTML;
        }

        return ['status' => 200, 'body' => PageLayout::render($site, $title, $content, $path, $entry), 'content_type' => 'text/html; charset=UTF-8'];
    }

    /** Custom collection single entry. */
    /**
     * @param array<string, mixed> $entry
     * @return array{status: int, body: string, content_type?: string}
     */
    private function renderCustomEntry(ServerRequestInterface $request, array $entry, string $collection, string $path): array
    {
        $site = $this->siteData->get();
        $data = $this->entryData($entry);
        $label = mb_strtoupper(mb_substr($collection, 0, 1)) . mb_substr($collection, 1);

        $templates = $this->loadTemplates();
        $tpl = $templates["{$collection}-single"] ?? null;

        $title = (string) ($entry['title'] ?? '') . ' | ' . $site['site_name'];
        $entry['seo_description'] = (string) ($data['seo']['meta_description'] ?? $data['excerpt'] ?? '');

        if ($tpl !== null) {
            $ctx = [...$data, ...$entry, 'url' => "{$path}/"];
            $content = $this->renderTemplate($tpl, $ctx);
        } else {
            $e = fn ($v) => TiptapRenderer::escapeHtml((string) ($v ?? ''));
            $fieldsHtml = '';
            foreach ($data as $key => $value) {
                if (is_scalar($value) && (string) $value !== '' && !in_array($key, ['content', 'html_code', 'seo'], true)) {
                    $fieldsHtml .= '<p><strong>' . $e(ucwords(str_replace('_', ' ', $key))) . ':</strong> ' . $e((string) $value) . '</p>';
                }
            }
            $content = "  <article class=\"post-detail\">\n    <header class=\"post-header\"><h1>{$e($entry['title'] ?? '')}</h1></header>\n    <div class=\"post-content\">{$fieldsHtml}</div>\n  </article>";
            $content .= "\n  <p><a href=\"/{$collection}/\">&larr; Back to {$e(mb_strtolower($label))}</a></p>";
        }

        return ['status' => 200, 'body' => PageLayout::render($site, $title, $content, $path, $entry), 'content_type' => 'text/html; charset=UTF-8'];
    }

    /** @param list<string> $templateHandles */
    /**
     * @param list<string> $templateHandles
     * @return array{status: int, body: string, content_type?: string}
     */
    private function renderArchive(ServerRequestInterface $request, string $collection, string $label, string $path, array $templateHandles): array
    {
        $site = $this->siteData->get();
        $entries = $this->listPublished($collection);

        $templates = $this->loadTemplates();
        $tpl = null;
        foreach ($templateHandles as $handle) {
            if (isset($templates[$handle])) {
                $tpl = $templates[$handle];
                break;
            }
        }

        if ($tpl !== null) {
            $items = array_map(fn (array $entry) => [
                ...$this->entryData($entry),
                ...$entry,
                'url' => $path . $entry['slug'] . '/',
                'date' => $this->formatDate((string) ($entry['published_at'] ?? '')),
            ], $entries);
            $content = $this->renderTemplate($tpl, ['label' => $label, 'items' => $items]);
        } else {
            $e = fn ($v) => TiptapRenderer::escapeHtml((string) ($v ?? ''));
            $cards = '';
            foreach ($entries as $entry) {
                $data = $this->entryData($entry);
                $excerpt = (string) ($data['excerpt'] ?? '');
                $published = (string) ($entry['published_at'] ?? '');
                $meta = $published !== '' ? '<div class="post-card-meta"><time datetime="' . $e($published) . '">' . $e($this->formatDate($published)) . '</time></div>' : '';
                $ex = $excerpt !== '' ? '<p class="post-card-excerpt">' . $e($excerpt) . '</p>' : '';
                $url = $path . $entry['slug'] . '/';
                $cards .= <<<HTML
        <article class="post-card">
          <h2 class="post-card-title"><a href="{$e($url)}">{$e($entry['title'] ?? '')}</a></h2>
          {$meta}
          {$ex}
          <a href="{$e($url)}" class="post-card-link">Read article &rarr;</a>
        </article>
HTML;
            }
            $empty = $entries === [] ? '<div class="empty-state"><p>No posts have been published yet.</p></div>' : '';
            $content = <<<HTML
  <header style="margin-bottom: 2rem;">
    <h1>{$e($label)}</h1>
  </header>

  <div class="posts-grid">
{$cards}
  </div>
  {$empty}
HTML;
        }

        return ['status' => 200, 'body' => PageLayout::render($site, "{$label} | " . $site['site_name'], $content, $path), 'content_type' => 'text/html; charset=UTF-8'];
    }

    private function notFoundHtml(ServerRequestInterface $request, string $path): string
    {
        return PageLayout::render404($this->siteData->get(), $path);
    }

    // -----------------------------------------------------------------
    // Sitemap / RSS
    // -----------------------------------------------------------------

    private function sitemapXml(): string
    {
        $base = $this->siteBaseUrl();
        $urls = [['loc' => $base . '/', 'lastmod' => null]];
        foreach ($this->listPublished('pages') as $entry) {
            if (in_array($entry['slug'], ['home', 'index'], true)) {
                continue;
            }
            if ($this->seoNoindex($entry)) {
                continue;
            }
            $urls[] = ['loc' => $base . '/' . trim((string) $entry['slug'], '/') . '/', 'lastmod' => $entry['updated_at'] ?? null];
        }
        foreach ($this->listPublished('posts') as $entry) {
            if ($this->seoNoindex($entry)) {
                continue;
            }
            $urls[] = ['loc' => $base . '/blog/' . trim((string) $entry['slug'], '/') . '/', 'lastmod' => $entry['updated_at'] ?? null];
        }

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        foreach ($urls as $url) {
            $lastmod = $url['lastmod'] ? '<lastmod>' . gmdate('Y-m-d\TH:i:s\Z', strtotime((string) $url['lastmod']) ?: time()) . '</lastmod>' : '';
            $xml .= "  <url><loc>" . TiptapRenderer::escapeHtml((string) $url['loc']) . "</loc>{$lastmod}</url>\n";
        }

        return $xml . "</urlset>\n";
    }

    private function rssXml(): string
    {
        $base = $this->siteBaseUrl();
        $site = $this->siteData->get();
        $siteName = TiptapRenderer::escapeHtml((string) $site['site_name']);
        $items = '';
        foreach (array_slice($this->listPublished('posts'), 0, 20) as $entry) {
            $data = $this->entryData($entry);
            $excerpt = (string) ($data['excerpt'] ?? '');
            $items .= "    <item>\n      <title>" . TiptapRenderer::escapeHtml((string) ($entry['title'] ?? '')) . "</title>\n"
                . '      <link>' . TiptapRenderer::escapeHtml("{$base}/blog/" . trim((string) ($entry['slug'] ?? ''), '/') . '/') . "</link>\n"
                . '      <guid isPermaLink="true">' . TiptapRenderer::escapeHtml("{$base}/blog/" . trim((string) ($entry['slug'] ?? ''), '/') . '/') . "</guid>\n"
                . '      <description>' . TiptapRenderer::escapeHtml($excerpt) . "</description>\n"
                . '      <pubDate>' . gmdate(DATE_RSS, strtotime((string) ($entry['published_at'] ?? 'now')) ?: time()) . "</pubDate>\n    </item>\n";
        }

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<rss version=\"2.0\"><channel>\n"
            . "  <title>{$siteName}</title>\n  <link>" . TiptapRenderer::escapeHtml($base) . "</link>\n"
            . '  <description>' . TiptapRenderer::escapeHtml((string) ($site['description'] ?? '')) . "</description>\n{$items}</channel></rss>\n";
    }

    private function siteBaseUrl(): string
    {
        $site = $this->siteData->get();
        $base = rtrim((string) ($site['canonical_url'] ?? ''), '/');
        if ($base === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base = $scheme . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }

        return $base;
    }

    // -----------------------------------------------------------------
    // Data access
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    private function findPublished(string $collection, string $slug): ?array
    {
        try {
            $prefix = $this->connection->getPrefix();
            $row = $this->connection->execute(
                "SELECT * FROM `{$prefix}c_{$collection}`
                 WHERE `slug` = ? AND `status` = 'published' AND `deleted_at` IS NULL LIMIT 1",
                [$slug]
            )->fetch();

            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<array<string, mixed>> */
    private function listPublished(string $collection): array
    {
        try {
            $prefix = $this->connection->getPrefix();
            $rows = $this->connection->execute(
                "SELECT * FROM `{$prefix}c_{$collection}`
                 WHERE `status` = 'published' AND `deleted_at` IS NULL
                 ORDER BY COALESCE(`published_at`, `created_at`) DESC"
            )->fetchAll();

            $out = [];
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $out[] = $row;
                }
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    private function collectionExists(string $handle): bool
    {
        // pages/posts/templates are handled separately; anything else must be a real collection table.
        if (in_array($handle, ['api', 'admin', 'oauth', 'mcp', 'health', 'media', 'installer', 'install', 'site-assets'], true)) {
            return false;
        }

        try {
            $prefix = $this->connection->getPrefix();
            $this->connection->execute("SELECT 1 FROM `{$prefix}c_{$handle}` LIMIT 1");

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function matchRedirect(string $path): ?array
    {
        try {
            $table = $this->connection->prefixTable('redirects');
            $row = $this->connection->execute(
                "SELECT `from_path`, `to_path` FROM `{$table}` WHERE `from_path` = ? OR `from_path` = ? LIMIT 1",
                [$path, $path . '/']
            )->fetch();

            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function entryData(array $entry): array
    {
        $data = is_string($entry['data'] ?? null) ? json_decode($entry['data'], true) : ($entry['data'] ?? []);
        if (!is_array($data)) {
            return [];
        }
        $seo = is_array($entry['seo'] ?? null)
            ? $entry['seo']
            : (is_string($entry['seo'] ?? null) ? json_decode($entry['seo'], true) : []);
        $data['seo'] = is_array($seo) ? $seo : [];

        return $data;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function seoNoindex(array $entry): bool
    {
        $seo = is_array($entry['seo'] ?? null)
            ? $entry['seo']
            : (is_string($entry['seo'] ?? null) ? (json_decode($entry['seo'], true) ?: []) : []);

        return (bool) ($seo['robots']['noindex'] ?? false);
    }

    private function formatDate(string $sqlDate): string
    {
        $ts = strtotime($sqlDate);

        return $ts ? date('M j, Y', $ts) : '';
    }

    // -----------------------------------------------------------------
    // Frontend templates (templates collection: tpl_handle + html_code)
    // -----------------------------------------------------------------

    /** @return array<string, string> */
    private function loadTemplates(): array
    {
        try {
            $prefix = $this->connection->getPrefix();
            $rows = $this->connection->execute(
                "SELECT `slug`, `data` FROM `{$prefix}c_templates` WHERE `status` = 'published' AND `deleted_at` IS NULL"
            )->fetchAll();
            $map = [];
            foreach ($rows as $row) {
                $data = is_string($row['data'] ?? null) ? json_decode($row['data'], true) : ($row['data'] ?? []);
                if (!is_array($data)) {
                    continue;
                }
                $handle = trim((string) ($data['tpl_handle'] ?? $row['slug'] ?? ''));
                $html = $data['html_code'] ?? null;
                if ($handle !== '' && is_string($html) && trim($html) !== '') {
                    $map[$handle] = $html;
                }
            }

            return $map;
        } catch (Throwable) {
            return [];
        }
    }

    /** Port of the starter's Mustache-like renderTemplate ({{ }}, {{{ }}}, {{#each}}, {{#if}}). */
    /**
     * @param array<string, mixed> $context
     */
    private function renderTemplate(string $template, array $context): string
    {
        $resolvePath = function (string $path, array $ctx) {
            $node = $ctx;
            foreach (explode('.', $path) as $key) {
                if (is_array($node) && array_key_exists($key, $node)) {
                    $node = $node[$key];
                } else {
                    return null;
                }
            }

            return $node;
        };

        $render = function (string $source, array $ctx) use (&$render, $resolvePath): string {
            $source = (string) preg_replace_callback(
                '/\{\{#each\s+([\w.]+)\}\}([\s\S]*?)\{\{\/each\}\}/',
                function ($m) use ($render, $resolvePath, $ctx) {
                    $list = $resolvePath($m[1], $ctx);
                    if (!is_array($list)) {
                        return '';
                    }
                    $out = '';
                    foreach ($list as $item) {
                        $out .= $render($m[2], is_array($item) ? [...$ctx, ...$item] : [...$ctx, 'this' => $item]);
                    }

                    return $out;
                },
                $source
            );
            $source = (string) preg_replace_callback(
                '/\{\{#if\s+([\w.]+)\}\}([\s\S]*?)\{\{\/if\}\}/',
                function ($m) use ($render, $resolvePath, $ctx) {
                    return $resolvePath($m[1], $ctx) ? $render($m[2], $ctx) : '';
                },
                $source
            );

            $source = (string) preg_replace_callback(
                '/\{\{\{\s*([\w.]+)\s*\}\}\}/',
                fn ($m) => (string) ($resolvePath($m[1], $ctx) ?? ''),
                $source
            );

            return (string) preg_replace_callback(
                '/\{\{\s*([\w.]+)\s*\}\}/',
                fn ($m) => TiptapRenderer::escapeHtml($resolvePath($m[1], $ctx)),
                $source
            );
        };

        return $render($template, $context);
    }

    // -----------------------------------------------------------------
    // Response plumbing + cache
    // -----------------------------------------------------------------

    private function renderCached(ServerRequestInterface $request, string $key, callable $producer): ResponseInterface
    {
        $cached = $this->cache->get($key);
        if ($cached !== null) {
            return $this->respond($cached, $key, false);
        }

        $result = $producer();
        $status = (int) $result['status'];
        $contentType = (string) ($result['content_type'] ?? 'text/html; charset=UTF-8');
        $body = (string) $result['body'];
        if (isset($result['redirect'])) {
            return $this->redirect($request, (string) $result['redirect']);
        }
        if ($status === 200) {
            $this->cache->set($key, $status, $contentType, $body);
        }

        return $this->respond(['status' => $status, 'body' => $body, 'content_type' => $contentType], $key, false);
    }

    /** @param array{status: int, body: string, content_type?: string} $payload */
    private function respond(array $payload, string $key, bool $longCache): ResponseInterface
    {
        $status = $payload['status'];
        $headers = [
            'Content-Type' => (string) ($payload['content_type'] ?? 'text/html; charset=UTF-8'),
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Cache-Control' => $longCache ? 'public, max-age=300' : ($status === 200 ? 'public, max-age=60' : 'no-store'),
        ];

        return new Response($status, $headers, $payload['body']);
    }

    private function redirect(ServerRequestInterface $request, string $target): ResponseInterface
    {
        // Relative targets stay on the site; allow only safe URL shapes.
        $safe = TiptapRenderer::isSafeUrl($target) ? $target : '/';

        return new Response(301, [
            'Location' => str_starts_with($safe, '/') ? rtrim($safe, '/') . '/' : $safe,
            'Cache-Control' => 'public, max-age=3600',
        ], '');
    }
}
