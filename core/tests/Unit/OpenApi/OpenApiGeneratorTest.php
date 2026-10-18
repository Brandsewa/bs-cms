<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\OpenApi;

use BsCms\Auth\Actor;
use BsCms\OpenApi\OpenApiGenerator;
use BsCms\Schema\CollectionRegistry;
use BsCms\Schema\FieldTypeRegistry;
use PHPUnit\Framework\TestCase;

final class OpenApiGeneratorTest extends TestCase
{
    public function testGeneratesValidOpenApiSpec(): void
    {
        $registry = $this->createMock(CollectionRegistry::class);
        $registry->method('all')->willReturn([
            'posts' => [
                'id' => 'col_123',
                'handle' => 'posts',
                'kind' => 'collection',
                'label_singular' => 'Post',
                'label_plural' => 'Posts',
                'fields' => [
                    'headline' => ['type' => 'text', 'config' => []],
                ],
            ],
            'site_settings' => [
                'id' => 'col_456',
                'handle' => 'site_settings',
                'kind' => 'single',
                'label_singular' => 'Site Setting',
                'label_plural' => 'Site Settings',
                'fields' => [],
            ],
        ]);

        $fieldTypeRegistry = new FieldTypeRegistry();
        $generator = new OpenApiGenerator($registry, $fieldTypeRegistry);

        $spec = $generator->generate();

        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertSame('BS CMS REST API', $spec['info']['title']);
        $this->assertContains('/api/v1', array_column($spec['servers'], 'url'));

        // Check paths exist
        $paths = $spec['paths'];
        $this->assertArrayHasKey('/auth/login', $paths);
        $this->assertArrayHasKey('/auth/session', $paths);
        $this->assertArrayHasKey('/collections', $paths);
        $this->assertArrayHasKey('/collections/{handle}', $paths);
        $this->assertArrayHasKey('/collections/posts/entries', $paths);
        $this->assertArrayHasKey('/collections/posts/entries/{id}', $paths);
        $this->assertArrayHasKey('/collections/posts/entries/bulk', $paths);
        $this->assertArrayHasKey('/collections/posts/entries/{id}/publish', $paths);
        $this->assertArrayHasKey('/collections/posts/entries/{id}/compare', $paths);
        $this->assertArrayHasKey('/singletons/site_settings', $paths);
        $this->assertArrayHasKey('/public/collections/posts/entries', $paths);
        $this->assertArrayHasKey('/webhooks', $paths);
        $this->assertArrayHasKey('/saved-views', $paths);
        $this->assertArrayHasKey('/jobs/{id}', $paths);

        // Check schemas components
        $this->assertArrayHasKey('posts_Entry', $spec['components']['schemas']);
        $this->assertArrayHasKey('headline', $spec['components']['schemas']['posts_Entry']['properties']);
    }
}
