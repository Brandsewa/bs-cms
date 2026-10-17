<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Auth;

use BsCms\Auth\RecoveryCodes;
use PHPUnit\Framework\TestCase;

final class RecoveryCodesTest extends TestCase
{
    private RecoveryCodes $recovery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recovery = new RecoveryCodes();
    }

    public function testGenerateProducesExpectedCounts(): void
    {
        $result = $this->recovery->generate(10);
        $this->assertCount(10, $result['codes']);
        $this->assertCount(10, $result['hashes']);

        // Check format xxxx-xxxx
        foreach ($result['codes'] as $code) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{4}-[a-f0-9]{4}$/', $code);
        }

        // Hashes should all be unique 64-char sha256 strings
        $this->assertCount(10, array_unique($result['hashes']));
        foreach ($result['hashes'] as $hash) {
            $this->assertSame(64, strlen($hash));
        }
    }

    public function testConsumeValidCodeRemovesItSingleUse(): void
    {
        $generated = $this->recovery->generate(5);
        $codes = $generated['codes'];
        $hashes = $generated['hashes'];

        $firstCode = $codes[0];
        $remaining = $this->recovery->consume($firstCode, $hashes);

        $this->assertNotNull($remaining);
        $this->assertCount(4, $remaining);

        // Reusing the same code must fail
        $secondAttempt = $this->recovery->consume($firstCode, $remaining);
        $this->assertNull($secondAttempt, 'Consumed recovery code cannot be reused');
    }

    public function testConsumeInvalidCodeFails(): void
    {
        $generated = $this->recovery->generate(3);
        $this->assertNull($this->recovery->consume('invalid-code', $generated['hashes']));
    }
}
