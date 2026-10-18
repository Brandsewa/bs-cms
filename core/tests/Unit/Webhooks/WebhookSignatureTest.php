<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Webhooks;

use BsCms\Webhooks\WebhookSignature;
use PHPUnit\Framework\TestCase;

final class WebhookSignatureTest extends TestCase
{
    private string $secret = 'whsec_test_secret_key_12345';
    private string $payload = '{"event":"entry.created","occurred_at":"2026-10-18T12:00:00Z","data":{"id":"01H123"}}';

    public function testSignProducesExpectedFormat(): void
    {
        $timestamp = 1770000000;
        $header = WebhookSignature::sign($this->payload, $this->secret, $timestamp);

        $this->assertStringStartsWith('t=1770000000,v1=', $header);
        $expectedHmac = hash_hmac('sha256', "t={$timestamp}." . $this->payload, $this->secret);
        $this->assertSame("t={$timestamp},v1={$expectedHmac}", $header);
    }

    public function testVerifyValidSignature(): void
    {
        $timestamp = 1770000000;
        $header = WebhookSignature::sign($this->payload, $this->secret, $timestamp);

        $isValid = WebhookSignature::verify(
            $header,
            $this->payload,
            $this->secret,
            toleranceSeconds: 300,
            currentTime: $timestamp + 30
        );

        $this->assertTrue($isValid);
    }

    public function testVerifyRejectsExpiredTimestamp(): void
    {
        $timestamp = 1770000000;
        $header = WebhookSignature::sign($this->payload, $this->secret, $timestamp);

        // 301 seconds later -> expired
        $isValid = WebhookSignature::verify(
            $header,
            $this->payload,
            $this->secret,
            toleranceSeconds: 300,
            currentTime: $timestamp + 301
        );

        $this->assertFalse($isValid);
    }

    public function testVerifyRejectsTamperedPayload(): void
    {
        $timestamp = 1770000000;
        $header = WebhookSignature::sign($this->payload, $this->secret, $timestamp);

        $tamperedPayload = '{"event":"entry.created","tampered":true}';
        $isValid = WebhookSignature::verify(
            $header,
            $tamperedPayload,
            $this->secret,
            toleranceSeconds: 300,
            currentTime: $timestamp
        );

        $this->assertFalse($isValid);
    }

    public function testVerifyRejectsWrongSecret(): void
    {
        $timestamp = 1770000000;
        $header = WebhookSignature::sign($this->payload, $this->secret, $timestamp);

        $isValid = WebhookSignature::verify(
            $header,
            $this->payload,
            'wrong_secret',
            toleranceSeconds: 300,
            currentTime: $timestamp
        );

        $this->assertFalse($isValid);
    }

    public function testVerifyRejectsMalformedHeader(): void
    {
        $this->assertFalse(WebhookSignature::verify('', $this->payload, $this->secret));
        $this->assertFalse(WebhookSignature::verify('invalid_header', $this->payload, $this->secret));
        $this->assertFalse(WebhookSignature::verify('t=1770000000', $this->payload, $this->secret));
        $this->assertFalse(WebhookSignature::verify('v1=abcdef', $this->payload, $this->secret));
    }
}
