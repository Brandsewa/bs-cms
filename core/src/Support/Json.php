<?php

declare(strict_types=1);

namespace BsCms\Support;

use JsonException;

/**
 * Strict, exception-throwing JSON encode/decode utilities.
 */
final class Json
{
    /**
     * Encode value to JSON string, throwing on error.
     *
     * @param mixed $value
     * @param int $flags
     * @return string
     * @throws JsonException
     */
    public static function encode(mixed $value, int $flags = 0): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | $flags
        );
    }

    /**
     * Decode JSON string to associative array or scalar, throwing on error.
     *
     * @param string $json
     * @param int $flags
     * @return mixed
     * @throws JsonException
     */
    public static function decode(string $json, int $flags = 0): mixed
    {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR | $flags);
    }

    /**
     * Check if a string is valid JSON without throwing.
     */
    public static function isValid(string $json): bool
    {
        if ($json === '') {
            return false;
        }

        try {
            json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            return true;
        } catch (JsonException) {
            return false;
        }
    }
}
