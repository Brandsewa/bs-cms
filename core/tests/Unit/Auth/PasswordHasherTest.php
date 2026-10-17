<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Auth;

use BsCms\Auth\PasswordHasher;
use PHPUnit\Framework\TestCase;

final class PasswordHasherTest extends TestCase
{
    private PasswordHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hasher = new PasswordHasher();
    }

    public function testHashAndVerify(): void
    {
        $password = 'CorrectHorseBatteryStaple2026!';
        $hash = $this->hasher->hash($password);

        $this->assertNotEmpty($hash);
        $this->assertTrue($this->hasher->verify($password, $hash));
        $this->assertFalse($this->hasher->verify('WrongPassword123!', $hash));
    }

    public function testNeedsRehashReturnsFalseForCurrentSettings(): void
    {
        $password = 'AStrongAndUniquePassphrase99!';
        $hash = $this->hasher->hash($password);
        $this->assertFalse($this->hasher->needsRehash($hash));
    }

    public function testNeedsRehashReturnsTrueForOldBcrypt(): void
    {
        $password = 'AStrongAndUniquePassphrase99!';
        $oldBcrypt = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

        if (defined('PASSWORD_ARGON2ID') && $this->hasher->getAlgo() === PASSWORD_ARGON2ID) {
            $this->assertTrue($this->hasher->needsRehash($oldBcrypt));
        } else {
            // Under bcrypt cost 12, cost 10 needs rehash
            $this->assertTrue($this->hasher->needsRehash($oldBcrypt));
        }
    }

    public function testValidatePolicyLengthRules(): void
    {
        $short = 'Short1!';
        $this->assertStringContainsString('at least 12 characters', (string) $this->hasher->validatePolicy($short));

        $huge = str_repeat('a', 257);
        $this->assertStringContainsString('not exceed 256 characters', (string) $this->hasher->validatePolicy($huge));

        $valid = 'UniquePassphraseThatIsNotInTop10kList!';
        $this->assertNull($this->hasher->validatePolicy($valid));
    }

    public function testValidatePolicyRejectsCommonPasswords(): void
    {
        $common1 = 'unbelievable';
        $this->assertStringContainsString('too common', (string) $this->hasher->validatePolicy($common1));

        $common2 = 'contortionist';
        $this->assertStringContainsString('too common', (string) $this->hasher->validatePolicy($common2));
    }

    public function testDummyVerifyExecutesWithoutError(): void
    {
        $start = microtime(true);
        $this->hasher->dummyVerify('some_attempted_password');
        $duration = microtime(true) - $start;

        $this->assertGreaterThan(0.001, $duration);
    }
}
