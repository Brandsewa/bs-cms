<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Auth;

use BsCms\Auth\SessionManager;
use BsCms\Database\Connection;
use PHPUnit\Framework\TestCase;

final class SessionManagerTest extends TestCase
{
    private SessionManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new SessionManager();
    }

    public function testBuildCookieHeaderHttpsUsesHostPrefixAndSecure(): void
    {
        $header = $this->manager->buildCookieHeader('token123', 3600, true);
        $this->assertStringStartsWith('__Host-bs_session=token123;', $header);
        $this->assertStringContainsString('; Secure', $header);
        $this->assertStringContainsString('; HttpOnly', $header);
        $this->assertStringContainsString('; SameSite=Lax', $header);
        $this->assertStringContainsString('; Path=/', $header);
    }

    public function testBuildCookieHeaderHttpUsesFallbackWithoutSecure(): void
    {
        $header = $this->manager->buildCookieHeader('token123', 3600, false);
        $this->assertStringStartsWith('bs_session=token123;', $header);
        $this->assertStringNotContainsString('Secure', $header);
        $this->assertStringContainsString('; HttpOnly', $header);
        $this->assertStringContainsString('; SameSite=Lax', $header);
    }

    public function testExtractTokenPrefersHostPrefix(): void
    {
        $cookies = [
            '__Host-bs_session' => 'secure_token',
            'bs_session' => 'insecure_token',
        ];
        $this->assertSame('secure_token', $this->manager->extractToken($cookies));

        $fallbackOnly = ['bs_session' => 'insecure_token'];
        $this->assertSame('insecure_token', $this->manager->extractToken($fallbackOnly));

        $empty = [];
        $this->assertNull($this->manager->extractToken($empty));
    }
}
