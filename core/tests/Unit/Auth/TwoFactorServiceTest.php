<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Auth;

use BsCms\Auth\TwoFactorService;
use BsCms\Security\Crypto;
use PHPUnit\Framework\TestCase;

final class TwoFactorServiceTest extends TestCase
{
    private TwoFactorService $twoFactor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoFactor = new TwoFactorService();
    }

    public function testBase32EncodeAndDecode(): void
    {
        $raw = 'Hello World 12345';
        $encoded = $this->twoFactor->base32Encode($raw);
        $decoded = $this->twoFactor->base32Decode($encoded);

        $this->assertSame($raw, $decoded);
    }

    public function testGenerateSecretAndTotpUri(): void
    {
        $secret = $this->twoFactor->generateSecret();
        $this->assertNotEmpty($secret);

        $uri = $this->twoFactor->getTotpUri($secret, 'admin@example.com', 'BS CMS');
        $this->assertStringStartsWith('otpauth://totp/BS%20CMS:admin%40example.com?', $uri);
        $this->assertStringContainsString("secret={$secret}", $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
    }

    public function testCodeGenerationAndVerification(): void
    {
        $secret = $this->twoFactor->generateSecret();
        $now = 1700000000;

        $code = $this->twoFactor->getCode($secret, $now);
        $this->assertSame(6, strlen($code));
        $this->assertTrue(ctype_digit($code));

        // Exact match
        $step = $this->twoFactor->verifyCode($secret, $code, null, $now);
        $this->assertNotNull($step);
        $this->assertSame((int) floor($now / 30), $step);

        // Wrong code fails
        $wrong = str_pad((string) (((int) $code + 1) % 1000000), 6, '0', STR_PAD_LEFT);
        $this->assertNull($this->twoFactor->verifyCode($secret, $wrong, null, $now));
    }

    public function testDriftWindow(): void
    {
        $secret = $this->twoFactor->generateSecret();
        $now = 1700000000;

        // Code from 25 seconds ago (within -1 step window)
        $pastCode = $this->twoFactor->getCode($secret, $now - 25);
        $pastStep = $this->twoFactor->verifyCode($secret, $pastCode, null, $now);
        $this->assertNotNull($pastStep);

        // Code from 25 seconds in future (within +1 step window)
        $futureCode = $this->twoFactor->getCode($secret, $now + 25);
        $futureStep = $this->twoFactor->verifyCode($secret, $futureCode, null, $now);
        $this->assertNotNull($futureStep);

        // Code from 65 seconds ago (outside window)
        $oldCode = $this->twoFactor->getCode($secret, $now - 65);
        $oldStep = $this->twoFactor->verifyCode($secret, $oldCode, null, $now);
        $this->assertNull($oldStep);
    }

    public function testReplayProtection(): void
    {
        $secret = $this->twoFactor->generateSecret();
        $now = 1700000000;
        $code = $this->twoFactor->getCode($secret, $now);

        // First verification succeeds and returns step
        $verifiedStep = $this->twoFactor->verifyCode($secret, $code, null, $now);
        $this->assertNotNull($verifiedStep);

        // Second verification with the same step recorded as lastUsedStep must be rejected!
        $replayAttempt = $this->twoFactor->verifyCode($secret, $code, $verifiedStep, $now);
        $this->assertNull($replayAttempt, 'Replaying the same TOTP code within the window must be rejected');
    }

    public function testPreviousStepAcceptedOnceOnly(): void
    {
        $secret = $this->twoFactor->generateSecret();
        $now = 1700000000;
        $prevCode = $this->twoFactor->getCode($secret, $now - 30);

        // First verification of previous step succeeds
        $verifiedStep = $this->twoFactor->verifyCode($secret, $prevCode, null, $now);
        $this->assertNotNull($verifiedStep);

        // Replay of previous step fails
        $replayAttempt = $this->twoFactor->verifyCode($secret, $prevCode, $verifiedStep, $now);
        $this->assertNull($replayAttempt, 'Replay of code from previous step must be rejected');

        // Current step code still works
        $currCode = $this->twoFactor->getCode($secret, $now);
        $currStep = $this->twoFactor->verifyCode($secret, $currCode, $verifiedStep, $now);
        $this->assertNotNull($currStep);
        $this->assertGreaterThan($verifiedStep, $currStep);
    }

    public function testStepUpReauthValidity(): void
    {
        $now = 1000;
        $this->assertTrue($this->twoFactor->isStepUpValid(950, 600, $now)); // 50 seconds ago
        $this->assertTrue($this->twoFactor->isStepUpValid(400, 600, $now)); // 600 seconds ago (boundary)
        $this->assertFalse($this->twoFactor->isStepUpValid(399, 600, $now)); // 601 seconds ago (expired)
        $this->assertFalse($this->twoFactor->isStepUpValid(null, 600, $now)); // Not verified
    }

    public function testSecretEncryptionAndDecryption(): void
    {
        if (!extension_loaded('sodium')) {
            $this->markTestSkipped('Sodium extension required for encryption test.');
        }

        $crypto = Crypto::fromAppKey(Crypto::generateKey());
        $secret = $this->twoFactor->generateSecret();

        $encrypted = $this->twoFactor->encryptSecret($secret, $crypto);
        $this->assertNotSame($secret, $encrypted);

        $decrypted = $this->twoFactor->decryptSecret($encrypted, $crypto);
        $this->assertSame($secret, $decrypted);
    }
}
