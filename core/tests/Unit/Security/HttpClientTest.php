<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Security;

use BsCms\Security\HttpClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HttpClientTest extends TestCase
{
    private HttpClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new HttpClient(2, 1024 * 1024, 3);
    }

    public function testRejectsUnsupportedScheme(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unsupported URL scheme 'file'");
        $this->client->get('file:///etc/passwd');
    }

    public function testBlocksPrivateAndLoopbackIps(): void
    {
        $restrictedIps = [
            '127.0.0.1',
            '10.0.0.1',
            '192.168.1.1',
            '172.16.0.1',
            '169.254.169.254', // AWS/GCP metadata
            '0.0.0.0',
            '::1',
            'fe80::1',
        ];

        foreach ($restrictedIps as $ip) {
            $caught = false;
            try {
                $this->client->assertPublicIp($ip);
            } catch (RuntimeException $e) {
                $caught = true;
                $this->assertStringContainsString('SSRF security violation', $e->getMessage());
            }
            $this->assertTrue($caught, "Expected IP {$ip} to trigger SSRF security violation.");
        }
    }

    public function testAllowsPublicIp(): void
    {
        // 8.8.8.8 and 1.1.1.1 are public Anycast DNS addresses
        $this->client->assertPublicIp('8.8.8.8');
        $this->client->assertPublicIp('1.1.1.1');
        $this->addToAssertionCount(2);
    }

    public function testResolveAndValidateHostFailsOnLocalhost(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SSRF security violation');
        $this->client->resolveAndValidateHost('127.0.0.1');
    }
}
