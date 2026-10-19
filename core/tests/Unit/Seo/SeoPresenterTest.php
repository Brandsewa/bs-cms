<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Seo;

use BsCms\Seo\SeoPresenter;
use BsCms\Seo\StructuredDataBuilder;
use PHPUnit\Framework\TestCase;

final class SeoPresenterTest extends TestCase
{
    public function testSeoPresenterExactKeySets(): void
    {
        $raw = [
            'title' => 'Sample Post Title',
            'description' => 'A comprehensive sample description of the post.',
            'canonical' => 'https://example.com/posts/sample-post',
            'robots' => [
                'noindex' => false,
                'nofollow' => false,
                'nosnippet' => false,
                'noarchive' => false,
                'max_snippet' => 160,
                'max_image_preview' => 'large',
            ],
            'open_graph' => [
                'title' => 'OG Sample Title',
                'description' => 'OG sample description',
                'type' => 'article',
                'url' => 'https://example.com/posts/sample-post',
                'image' => 'https://example.com/media/og-image.jpg',
                'site_name' => 'BS CMS Example',
            ],
            'twitter' => [
                'card' => 'summary_large_image',
                'title' => 'Twitter Sample Title',
                'description' => 'Twitter sample description',
                'image' => 'https://example.com/media/og-image.jpg',
            ],
        ];

        $presented = SeoPresenter::present($raw);

        $expectedTopKeys = ['canonical', 'description', 'open_graph', 'robots', 'title', 'twitter'];
        sort($expectedTopKeys);
        $actualTopKeys = array_keys($presented);
        sort($actualTopKeys);
        $this->assertSame($expectedTopKeys, $actualTopKeys, 'SeoPresenter top-level keys must match canonical specification');

        $expectedRobotsKeys = ['max_image_preview', 'max_snippet', 'noarchive', 'nofollow', 'noindex', 'nosnippet'];
        sort($expectedRobotsKeys);
        $actualRobotsKeys = array_keys($presented['robots']);
        sort($actualRobotsKeys);
        $this->assertSame($expectedRobotsKeys, $actualRobotsKeys, 'Robots key set must match canonical specification');

        $expectedOgKeys = ['description', 'image', 'site_name', 'title', 'type', 'url'];
        sort($expectedOgKeys);
        $actualOgKeys = array_keys($presented['open_graph']);
        sort($actualOgKeys);
        $this->assertSame($expectedOgKeys, $actualOgKeys, 'OpenGraph key set must match canonical specification');

        $expectedTwitterKeys = ['card', 'description', 'image', 'title'];
        sort($expectedTwitterKeys);
        $actualTwitterKeys = array_keys($presented['twitter']);
        sort($actualTwitterKeys);
        $this->assertSame($expectedTwitterKeys, $actualTwitterKeys, 'Twitter key set must match canonical specification');
    }

    public function testStructuredDataBuilderGeneratesValidGraph(): void
    {
        $site = [
            'site_name' => 'Test Site',
            'site_url' => 'https://example.com',
            'logo_url' => 'https://example.com/media/logo.png',
        ];

        $entity = [
            'type' => 'post',
            'title' => 'How to Deploy on Shared Hosting',
            'description' => 'Step-by-step guide to deploying lightweight CMS.',
            'canonical' => 'https://example.com/posts/how-to-deploy',
            'published_at' => '2026-10-19 10:00:00',
            'updated_at' => '2026-10-19 11:00:00',
            'author_name' => 'Jane Doe',
            'image_url' => 'https://example.com/media/deploy.webp',
            'faq' => [
                ['question' => 'Does it need root?', 'answer' => 'No, standard shared hosting is supported.'],
            ],
        ];

        $jsonLd = StructuredDataBuilder::build($site, $entity);

        $this->assertSame('https://schema.org', $jsonLd['@context']);
        $this->assertNotEmpty($jsonLd['@graph']);

        $types = array_column($jsonLd['@graph'], '@type');
        $this->assertContains('Organization', $types);
        $this->assertContains('WebSite', $types);
        $this->assertContains('BreadcrumbList', $types);
        $this->assertContains('WebPage', $types);
        $this->assertContains('BlogPosting', $types);
        $this->assertContains('FAQPage', $types);
    }
}
