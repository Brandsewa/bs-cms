<?php

declare(strict_types=1);

namespace BsCms\Security;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/**
 * Universally Unique Lexicographically Sortable Identifier (ULID).
 * Implements monotonic 26-character Crockford Base32 ASCII strings.
 */
final class Ulid
{
    public const ENCODING = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private static int $lastTime = 0;
    private static string $lastRandom = '';

    /**
     * Generate a new monotonic ULID string.
     */
    public static function generate(?int $timestampMs = null): string
    {
        $time = $timestampMs ?? (int) (microtime(true) * 1000);

        if ($time === self::$lastTime) {
            $random = self::incrementRandom(self::$lastRandom);
        } else {
            $random = self::randomBytes(10);
            self::$lastTime = $time;
        }

        self::$lastRandom = $random;

        return self::encodeTime($time) . self::encodeRandom($random);
    }

    /**
     * Validate whether a string is a valid 26-character Crockford Base32 ULID.
     */
    public static function isValid(string $ulid): bool
    {
        if (strlen($ulid) !== 26) {
            return false;
        }

        return (bool) preg_match('/^[0-7][0123456789ABCDEFGHJKMNPQRSTVWXYZ]{25}$/i', $ulid);
    }

    /**
     * Extract the Unix timestamp in milliseconds from a ULID.
     */
    public static function getTimestamp(string $ulid): int
    {
        if (!self::isValid($ulid)) {
            throw new InvalidArgumentException("Invalid ULID: {$ulid}");
        }

        $timeChars = strtoupper(substr($ulid, 0, 10));
        $timestamp = 0;

        for ($i = 0; $i < 10; ++$i) {
            $char = $timeChars[$i];
            $value = strpos(self::ENCODING, $char);
            if ($value === false) {
                throw new InvalidArgumentException("Invalid Crockford Base32 character: {$char}");
            }
            $timestamp = ($timestamp * 32) + $value;
        }

        return $timestamp;
    }

    /**
     * Extract the UTC DateTimeImmutable from a ULID.
     */
    public static function getDateTime(string $ulid): DateTimeImmutable
    {
        $timestampMs = self::getTimestamp($ulid);
        $seconds = (int) ($timestampMs / 1000);
        $micro = ($timestampMs % 1000) * 1000;

        $dt = DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', $seconds, $micro));
        if ($dt === false) {
            throw new RuntimeException('Failed to construct DateTimeImmutable from ULID timestamp');
        }

        return $dt->setTimezone(new DateTimeZone('UTC'));
    }

    private static function encodeTime(int $time): string
    {
        $chars = '';
        for ($i = 9; $i >= 0; --$i) {
            $mod = $time % 32;
            $chars = self::ENCODING[$mod] . $chars;
            $time = (int) ($time / 32);
        }

        return $chars;
    }

    private static function encodeRandom(string $bytes): string
    {
        // 10 bytes = 80 bits = 16 5-bit Crockford Base32 characters
        $chars = '';
        $binary = '';
        for ($i = 0; $i < strlen($bytes); ++$i) {
            $binary .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
        }

        for ($i = 0; $i < 16; ++$i) {
            $chunk = substr($binary, $i * 5, 5);
            $chars .= self::ENCODING[(int) bindec($chunk)];
        }

        return $chars;
    }

    /**
     * @param int<1, max> $count
     */
    private static function randomBytes(int $count): string
    {
        return random_bytes($count);
    }

    private static function incrementRandom(string $random): string
    {
        $bytes = array_values(unpack('C*', $random) ?: []);
        for ($i = count($bytes) - 1; $i >= 0; --$i) {
            if ($bytes[$i] < 255) {
                ++$bytes[$i];
                return pack('C*', ...$bytes);
            }
            $bytes[$i] = 0;
        }

        throw new RuntimeException('ULID random component overflow within millisecond');
    }
}
