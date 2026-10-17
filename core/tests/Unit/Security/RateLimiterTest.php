<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Security;

use BsCms\Security\RateLimiter;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear();
    }

    public function testRateLimiterEnforcesLimitAndCalculatesRetryAfter(): void
    {
        $limiter = new RateLimiter(3, 60);

        $res1 = $limiter->hit('ip_test_1');
        $this->assertTrue($res1['allowed']);
        $this->assertSame(2, $res1['remaining']);
        $this->assertSame(0, $res1['retry_after']);

        $res2 = $limiter->hit('ip_test_1');
        $this->assertTrue($res2['allowed']);
        $this->assertSame(1, $res2['remaining']);

        $res3 = $limiter->hit('ip_test_1');
        $this->assertTrue($res3['allowed']);
        $this->assertSame(0, $res3['remaining']);

        // 4th hit should be rejected
        $res4 = $limiter->hit('ip_test_1');
        $this->assertFalse($res4['allowed']);
        $this->assertSame(0, $res4['remaining']);
        $this->assertGreaterThan(0, $res4['retry_after']);

        // Different key is unaffected
        $other = $limiter->hit('ip_test_2');
        $this->assertTrue($other['allowed']);
        $this->assertSame(2, $other['remaining']);
    }

    public function testSpoofedXForwardedForIsIgnoredFromUntrustedPeer(): void
    {
        $limiter = new RateLimiter(60, 60, ['10.0.0.100']); // Only 10.0.0.100 is trusted proxy

        $server = ['REMOTE_ADDR' => '203.0.113.195'];
        $headers = ['x-forwarded-for' => '1.1.1.1'];

        // Attacker sends spoofed header from untrusted IP
        $ip = $limiter->resolveClientIp($server, $headers);
        $this->assertSame('203.0.113.195', $ip);
    }

    public function testHonoursXForwardedForFromTrustedProxy(): void
    {
        $trustedProxyIp = '10.0.0.100';
        $limiter = new RateLimiter(60, 60, [$trustedProxyIp]);

        $server = ['REMOTE_ADDR' => $trustedProxyIp];
        $headers = ['x-forwarded-for' => '198.51.100.25, 10.0.0.100'];

        $ip = $limiter->resolveClientIp($server, $headers);
        $this->assertSame('198.51.100.25', $ip);
    }
}
