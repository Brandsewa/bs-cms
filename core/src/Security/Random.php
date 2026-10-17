<?php

declare(strict_types=1);

namespace BsCms\Security;

use RuntimeException;

/**
 * Cryptographically secure random data and token generation.
 */
final class Random
{
    /**
     * Generate raw cryptographically secure random bytes.
     */
    public static function bytes(int $length): string
    {
        if ($length < 1) {
            throw new RuntimeException('Length must be greater than zero');
        }

        return random_bytes($length);
    }

    /**
     * Generate a hex-encoded random string.
     */
    public static function hex(int $bytes = 32): string
    {
        return bin2hex(self::bytes($bytes));
    }

    /**
     * Generate a URL-safe base64 encoded token (without padding).
     */
    public static function token(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(self::bytes($bytes)), '+/', '-_'), '=');
    }

    /**
     * Generate a random integer between min and max (inclusive).
     */
    public static function int(int $min, int $max): int
    {
        return random_int($min, $max);
    }
}
