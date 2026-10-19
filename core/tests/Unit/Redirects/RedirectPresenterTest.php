<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Redirects;

use BsCms\Redirects\RedirectPresenter;
use PHPUnit\Framework\TestCase;

final class RedirectPresenterTest extends TestCase
{
    public function testRedirectPresenterExactKeySet(): void
    {
        $raw = [
            'id' => '01HRED00000000000000000001',
            'from_path' => '/old-path',
            'to_path' => '/new-path',
            'status_code' => 301,
            'match_type' => 'exact',
            'is_auto' => 1,
            'hits' => 42,
            'last_hit_at' => '2026-10-19 12:00:00',
            'created_by' => '01HUSER0000000000000000001',
            'created_at' => '2026-10-19 11:00:00',
            'from_hash' => hash('sha256', '/old-path'),
        ];

        $presented = RedirectPresenter::present($raw);

        $expectedKeys = [
            'id',
            'from_path',
            'to_path',
            'status_code',
            'match_type',
            'is_auto',
            'hits',
            'last_hit_at',
            'created_by',
            'created_at',
        ];
        sort($expectedKeys);

        $actualKeys = array_keys($presented);
        sort($actualKeys);

        $this->assertSame(
            $expectedKeys,
            $actualKeys,
            'RedirectPresenter must expose exactly the canonical 10 keys (no leaked internal hashes)'
        );

        $this->assertSame('01HRED00000000000000000001', $presented['id']);
        $this->assertSame('/old-path', $presented['from_path']);
        $this->assertSame('/new-path', $presented['to_path']);
        $this->assertSame(301, $presented['status_code']);
        $this->assertSame('exact', $presented['match_type']);
        $this->assertTrue($presented['is_auto']);
        $this->assertSame(42, $presented['hits']);
    }
}
