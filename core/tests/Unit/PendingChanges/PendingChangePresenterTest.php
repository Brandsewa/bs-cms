<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\PendingChanges;

use BsCms\PendingChanges\PendingChangePresenter;
use PHPUnit\Framework\TestCase;

final class PendingChangePresenterTest extends TestCase
{
    public function testPresentFormatsExactKeySetAndStripsInternalFields(): void
    {
        $raw = [
            'id' => '01JABCD1234EFGH5678IJKL90',
            'actor_type' => 'oauth',
            'actor_label' => 'Claude (via Anna)',
            'oauth_grant_id' => '01JGRANT1234567890',
            'api_token_id' => null,
            'on_behalf_of' => 'usr_anna',
            'operation' => 'publish_entry',
            'subject_type' => 'entry',
            'subject_id' => 'ent_123',
            'payload' => '{"collection":"posts","id":"ent_123"}',
            'diff' => '{"operation":"publish_entry","status":{"old":"draft","new":"published"}}',
            'status' => 'pending',
            'decided_by' => null,
            'decided_at' => null,
            'decision_note' => null,
            'expires_at' => '2026-10-27 12:00:00.000',
            'created_at' => '2026-10-20 12:00:00.000',
            'internal_secret' => 'super_secret',
            'password_hash' => '$2y$12$...',
        ];

        $presented = PendingChangePresenter::present($raw);

        $expectedKeys = [
            'id',
            'actor_type',
            'actor_label',
            'oauth_grant_id',
            'api_token_id',
            'on_behalf_of',
            'operation',
            'subject_type',
            'subject_id',
            'payload',
            'diff',
            'status',
            'decided_by',
            'decided_at',
            'decision_note',
            'expires_at',
            'created_at',
        ];

        $this->assertSame($expectedKeys, array_keys($presented));
        $this->assertArrayNotHasKey('internal_secret', $presented);
        $this->assertArrayNotHasKey('password_hash', $presented);
        $this->assertSame('01JABCD1234EFGH5678IJKL90', $presented['id']);
        $this->assertSame('Claude (via Anna)', $presented['actor_label']);
        $this->assertSame(['collection' => 'posts', 'id' => 'ent_123'], $presented['payload']);
        $this->assertIsArray($presented['diff']);
        $this->assertSame('pending', $presented['status']);
    }
}
