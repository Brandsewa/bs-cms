<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Auth;

use BsCms\Auth\Actor;
use PHPUnit\Framework\TestCase;

final class ActorTest extends TestCase
{
    public function testSystemActor(): void
    {
        $actor = Actor::system();
        $this->assertTrue($actor->isSystem());
        $this->assertFalse($actor->isUser());
        $this->assertFalse($actor->isAi());
        $this->assertSame('system', $actor->id);
        $this->assertNull($actor->onBehalfOf);
    }

    public function testUserActor(): void
    {
        $actor = Actor::user('01JB0000000000000000000001', 'Jane Doe', ['01JB0000000000000000000004']);
        $this->assertTrue($actor->isUser());
        $this->assertFalse($actor->isSystem());
        $this->assertFalse($actor->isAi());
        $this->assertSame('01JB0000000000000000000001', $actor->id);
        $this->assertSame('01JB0000000000000000000001', $actor->onBehalfOf);
        $this->assertTrue($actor->hasRole('01JB0000000000000000000004'));
        $this->assertFalse($actor->hasRole('non_existent'));
    }

    public function testApiTokenAndAiClientActors(): void
    {
        $tokenActor = Actor::apiToken('tok_123', 'CI Deployment', 'usr_456', ['role_789'], Actor::AI_MODE_DRAFT_ONLY);
        $this->assertTrue($tokenActor->isAi());
        $this->assertFalse($tokenActor->isUser());
        $this->assertSame('usr_456', $tokenActor->onBehalfOf);
        $this->assertSame(Actor::AI_MODE_DRAFT_ONLY, $tokenActor->aiMode);

        $aiActor = Actor::aiClient('client_abc', 'Claude Assistant', 'usr_456', ['role_789'], Actor::AI_MODE_APPROVAL_REQUIRED);
        $this->assertTrue($aiActor->isAi());
        $this->assertSame(Actor::AI_MODE_APPROVAL_REQUIRED, $aiActor->aiMode);
    }
}
