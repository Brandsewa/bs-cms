<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Content;

use BsCms\Auth\Actor;
use BsCms\Authz\Authorizer;
use BsCms\Content\Presenters\EntryPresenter;
use PHPUnit\Framework\TestCase;

final class EntryPresenterTest extends TestCase
{
    public function testEntryPresenterIncludesDeletedAtInKeySet(): void
    {
        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('getAttribute')->with(\PDO::ATTR_SERVER_VERSION)->willReturn('8.0.35-MySQL');
        $connection = new \BsCms\Database\Connection(['pdo' => $mockPdo, 'prefix' => 'bs_']);
        $compiler = new \BsCms\Authz\PermissionCompiler($connection);
        $authorizer = new Authorizer($connection, $compiler);

        $actor = Actor::system();

        $collection = [
            'handle' => 'posts',
            'fields' => [
                'headline' => ['type' => 'text'],
            ],
        ];

        // Active entry (deleted_at is null)
        $rawActive = [
            'id' => '01JPOST1234567890ABCDEF12',
            'collection_id' => '01JCOL1234567890ABCDEF12',
            'locale' => 'en',
            'slug' => 'test-post',
            'title' => 'Test Post',
            'status' => 'published',
            'author_id' => '01JUSER1234567890ABCDEF123',
            'published_at' => '2026-10-21 10:00:00',
            'created_at' => '2026-10-21 09:00:00',
            'updated_at' => '2026-10-21 10:00:00',
            'deleted_at' => null,
            'search_text' => 'internal index',
            'created_by' => '01JUSER1234567890ABCDEF123',
            'updated_by' => '01JUSER1234567890ABCDEF123',
            'data' => [
                'headline' => 'Welcome to the blog',
            ],
        ];

        $presentedActive = EntryPresenter::forAuthenticated($rawActive, $collection, $actor, $authorizer);

        $this->assertArrayHasKey('deleted_at', $presentedActive, 'EntryPresenter::forAuthenticated must include deleted_at in key-set');
        $this->assertNull($presentedActive['deleted_at']);
        $this->assertArrayNotHasKey('search_text', $presentedActive);
        $this->assertArrayNotHasKey('created_by', $presentedActive);
        $this->assertArrayNotHasKey('updated_by', $presentedActive);

        // Soft-deleted entry (deleted_at is string timestamp)
        $rawDeleted = $rawActive;
        $rawDeleted['deleted_at'] = '2026-10-21 10:30:00.000';

        $presentedDeleted = EntryPresenter::forAuthenticated($rawDeleted, $collection, $actor, $authorizer);
        $this->assertArrayHasKey('deleted_at', $presentedDeleted);
        $this->assertSame('2026-10-21 10:30:00.000', $presentedDeleted['deleted_at']);
    }
}
