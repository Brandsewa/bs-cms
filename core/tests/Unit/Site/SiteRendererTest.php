<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Site;

use BsCms\Content\Cache\RenderedPageCache;
use BsCms\Site\PuckRenderer;
use BsCms\Site\TiptapRenderer;
use PHPUnit\Framework\TestCase;

final class SiteRendererTest extends TestCase
{
    // -----------------------------------------------------------------
    // TiptapRenderer
    // -----------------------------------------------------------------

    public function testRendersParagraphWithMarks(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [
                    ['type' => 'text', 'text' => 'Hello ', 'marks' => null],
                    ['type' => 'text', 'text' => 'world', 'marks' => [['type' => 'bold']]],
                    ['type' => 'text', 'text' => ' & friends', 'marks' => [['type' => 'italic']]],
                ]],
            ],
        ];
        $html = (new TiptapRenderer())->render($doc);
        $this->assertStringContainsString('<p>Hello <strong>world</strong><em> &amp; friends</em></p>', $html);
    }

    public function testRendersHeadingAndLists(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [
                ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Title']]],
                ['type' => 'bulletList', 'content' => [
                    ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'One']]]]],
                    ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Two']]]]],
                ]],
            ],
        ];
        $html = (new TiptapRenderer())->render($doc);
        $this->assertStringContainsString('<h2>Title</h2>', $html);
        $this->assertStringContainsString('<ul>', $html);
        $this->assertSame(2, substr_count($html, '<li>'));
    }

    public function testLinkMarkEscapesHrefAndAddsRel(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [
                    ['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com/a%22b']]]],
                ]],
            ],
        ];
        $html = (new TiptapRenderer())->render($doc);
        $this->assertStringContainsString('href="https://example.com/a%22b"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    public function testUnsafeUrlFallsBackToHash(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [
                    ['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]],
                ]],
            ],
        ];
        $html = (new TiptapRenderer())->render($doc);
        $this->assertStringContainsString('href="#"', $html);
    }

    public function testObfuscatedScriptSchemeIsRejected(): void
    {
        $this->assertFalse(TiptapRenderer::isSafeUrl('&#106;avascript:alert(1)'));
        $this->assertFalse(TiptapRenderer::isSafeUrl('//evil.example'));
        $this->assertTrue(TiptapRenderer::isSafeUrl('/relative/path'));
        $this->assertTrue(TiptapRenderer::isSafeUrl('mailto:a@b.c'));
        $this->assertTrue(TiptapRenderer::isSafeUrl('bs://entry/foo'));
    }

    public function testCalloutAndFaqNodes(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [
                ['type' => 'callout', 'attrs' => ['variant' => 'warning', 'title' => 'Careful'], 'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'msg']]],
                ]],
                ['type' => 'faq', 'attrs' => ['items' => [['question' => 'Q?', 'answer' => 'A.']]]],
            ],
        ];
        $html = (new TiptapRenderer())->render($doc);
        $this->assertStringContainsString('callout-warning', $html);
        $this->assertStringContainsString('<strong>Careful</strong>', $html);
        $this->assertStringContainsString('<summary>Q?</summary>', $html);
    }

    public function testEmptyDocReturnsEmptyString(): void
    {
        $this->assertSame('', (new TiptapRenderer())->render(null));
        $this->assertSame('', (new TiptapRenderer())->render(['type' => 'doc']));
    }

    // -----------------------------------------------------------------
    // PuckRenderer
    // -----------------------------------------------------------------

    public function testRendersHeroBlock(): void
    {
        $html = (new PuckRenderer())->render([
            'content' => [
                ['type' => 'Hero', 'props' => [
                    'id' => 'hero-1',
                    'heading' => 'Welcome & hello',
                    'text' => 'Intro text',
                    'ctaLabel' => 'Read more',
                    'ctaUrl' => '/blog/',
                ]],
            ],
        ]);
        $this->assertStringContainsString('<section id="hero-1" class="bs-hero">', $html);
        $this->assertStringContainsString('<h1 class="bs-hero__heading">Welcome &amp; hello</h1>', $html);
        $this->assertStringContainsString('<a class="bs-hero__cta bs-btn" href="/blog/">Read more</a>', $html);
    }

    public function testRichTextBlockIsVerbatim(): void
    {
        $html = (new PuckRenderer())->render([
            'content' => [['type' => 'RichText', 'props' => ['html' => '<p>Raw <b>HTML</b></p>']]],
        ]);
        $this->assertSame('<div class="bs-richtext"><p>Raw <b>HTML</b></p></div>', $html);
    }

    public function testUnknownBlockTypeIsDropped(): void
    {
        $html = (new PuckRenderer())->render([
            'content' => [
                ['type' => 'Mystery', 'props' => ['x' => 1]],
                ['type' => 'Spacer', 'props' => ['height' => 32]],
            ],
        ]);
        $this->assertStringContainsString('bs-spacer', $html);
        $this->assertStringNotContainsString('Mystery', $html);
    }

    public function testFaqAndFeaturesBlocks(): void
    {
        $html = (new PuckRenderer())->render([
            'content' => [
                ['type' => 'Faq', 'props' => ['items' => [['question' => 'What?', 'answer' => 'This.']]]],
                ['type' => 'FeaturesGrid', 'props' => ['columns' => '3', 'items' => [['title' => 'Fast', 'description' => 'Very']]]],
            ],
        ]);
        $this->assertStringContainsString('bs-faq__question', $html);
        $this->assertStringContainsString('bs-features--3', $html);
        $this->assertStringContainsString('<h3 class="bs-features__title">Fast</h3>', $html);
    }

    public function testContactFormPlaceholder(): void
    {
        $html = (new PuckRenderer())->render([
            'content' => [['type' => 'ContactForm', 'props' => ['formHandle' => 'contact-us', 'heading' => 'Talk to us', 'subheading' => '']]],
        ]);
        $this->assertStringContainsString('data-bs-form="contact-us"', $html);
        $this->assertStringContainsString('bs-contact-form__heading', $html);
    }

    // -----------------------------------------------------------------
    // RenderedPageCache
    // -----------------------------------------------------------------

    public function testPageCacheSetGetClear(): void
    {
        $dir = sys_get_temp_dir() . '/bs_cms_site_cache_' . bin2hex(random_bytes(6));
        $cache = new RenderedPageCache($dir);

        $this->assertNull($cache->get('/home'));
        $cache->set('/home', 200, 'text/html; charset=UTF-8', '<html>cached</html>');
        $hit = $cache->get('/home');
        $this->assertNotNull($hit);
        $this->assertSame(200, $hit['status']);
        $this->assertSame('<html>cached</html>', $hit['body']);

        $cache->set('/blog/foo', 200, 'text/html; charset=UTF-8', 'post');
        $cache->clear();
        $this->assertNull($cache->get('/home'));
        $this->assertNull($cache->get('/blog/foo'));

        $this->deleteRecursive($dir);
    }

    private function deleteRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->deleteRecursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
