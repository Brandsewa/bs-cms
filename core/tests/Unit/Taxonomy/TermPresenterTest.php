<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Taxonomy;

use BsCms\Taxonomy\TermPresenter;
use PHPUnit\Framework\TestCase;

final class TermPresenterTest extends TestCase
{
    public function testPublicTermPresenterExactKeySet(): void
    {
        $rawTerm = [
            'id' => '01HTERM000000000000000001',
            'taxonomy_id' => '01HTAX000000000000000001',
            'parent_id' => null,
            'name' => 'Technology',
            'slug' => 'technology',
            'slug_path' => 'technology',
            'description' => 'Tech news and articles',
            'path' => '/technology/',
            'depth' => 0,
            'position' => 0,
            'entry_count' => 12,
            'is_locked' => false,
            'image_media_id' => null,
            'color' => '#0070f3',
            'icon' => 'laptop',
            'seo' => ['title' => 'Tech News'],
            'created_at' => '2026-10-19 00:00:00',
            'updated_at' => '2026-10-19 00:00:00',
        ];

        $presented = TermPresenter::forPublic($rawTerm);

        $expectedKeys = [
            'id',
            'taxonomy_id',
            'parent_id',
            'name',
            'slug',
            'slug_path',
            'description',
            'depth',
            'position',
            'image_media_id',
            'color',
            'icon',
            'entry_count',
            'seo',
        ];
        sort($expectedKeys);

        $actualKeys = array_keys($presented);
        sort($actualKeys);

        $this->assertSame(
            $expectedKeys,
            $actualKeys,
            "TermPresenter::forPublic must expose the exact expected canonical key-set."
        );

        $this->assertSame('01HTERM000000000000000001', $presented['id']);
        $this->assertSame('Technology', $presented['name']);
        $this->assertSame(12, $presented['entry_count']);
        $this->assertSame('#0070f3', $presented['color']);
    }
}
