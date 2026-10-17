<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Security;

use BsCms\Security\Random;
use BsCms\Security\Ulid;
use PHPUnit\Framework\TestCase;

final class UlidAndRandomTest extends TestCase
{
    public function testUlidFormatAndValidation(): void
    {
        $ulid = Ulid::generate();

        $this->assertSame(26, strlen($ulid));
        $this->assertTrue(Ulid::isValid($ulid));
        $this->assertFalse(Ulid::isValid('invalid_length'));
        $this->assertFalse(Ulid::isValid('01AN4Z07BY79KA1307SR9X4MV!')); // invalid char
    }

    public function testUlidMonotonicityWithinSameMillisecond(): void
    {
        $fixedMs = 1700000000123;
        $ulids = [];

        for ($i = 0; $i < 50; ++$i) {
            $ulids[] = Ulid::generate($fixedMs);
        }

        // Check strict ascending sort order
        for ($i = 1; $i < count($ulids); ++$i) {
            $this->assertGreaterThan(0, strcmp($ulids[$i], $ulids[$i - 1]), "ULID {$ulids[$i]} is not greater than {$ulids[$i - 1]}");
        }
    }

    public function testUlidTimestampExtraction(): void
    {
        $timeMs = 1700000000456;
        $ulid = Ulid::generate($timeMs);

        $extractedMs = Ulid::getTimestamp($ulid);
        $this->assertSame($timeMs, $extractedMs);

        $dt = Ulid::getDateTime($ulid);
        $this->assertSame('UTC', $dt->getTimezone()->getName());
        $this->assertSame((int) ($timeMs / 1000), $dt->getTimestamp());
    }

    public function testRandomGeneratorProducesSecureBytesAndTokens(): void
    {
        $bytes = Random::bytes(16);
        $this->assertSame(16, strlen($bytes));

        $hex = Random::hex(16);
        $this->assertSame(32, strlen($hex));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $hex);

        $token = Random::token(32);
        $this->assertNotEmpty($token);
        $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $token); // URL-safe without padding

        $int = Random::int(10, 20);
        $this->assertGreaterThanOrEqual(10, $int);
        $this->assertLessThanOrEqual(20, $int);
    }
}
