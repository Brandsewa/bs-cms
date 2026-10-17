<?php

declare(strict_types=1);

namespace BsCms\Support;

/**
 * Array manipulation helpers for dot-notation access, extraction, and filtering.
 */
final class Arr
{
    /**
     * Get an item from an array using "dot" notation.
     *
     * @param array<string, mixed> $array
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    public static function get(array $array, ?string $key, mixed $default = null): mixed
    {
        if ($key === null) {
            return $array;
        }

        if (array_key_exists($key, $array)) {
            return $array[$key];
        }

        if (!str_contains($key, '.')) {
            return $array[$key] ?? $default;
        }

        $current = $array;
        foreach (explode('.', $key) as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
            } else {
                return $default;
            }
        }

        return $current;
    }

    /**
     * Set an array item to a given value using "dot" notation.
     *
     * @param array<string, mixed> $array
     * @param string $key
     * @param mixed $value
     * @return array<string, mixed>
     */
    public static function set(array &$array, string $key, mixed $value): array
    {
        $keys = explode('.', $key);
        $current = &$array;

        foreach ($keys as $i => $segment) {
            if (count($keys) === 1) {
                break;
            }

            unset($keys[$i]);

            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }

            $current = &$current[$segment];
        }

        $current[array_shift($keys)] = $value;

        return $array;
    }

    /**
     * Check if a given key exists in an array using "dot" notation.
     *
     * @param array<string, mixed> $array
     * @param string $key
     * @return bool
     */
    public static function has(array $array, string $key): bool
    {
        if (array_key_exists($key, $array)) {
            return true;
        }

        $current = $array;
        foreach (explode('.', $key) as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
            } else {
                return false;
            }
        }

        return true;
    }

    /**
     * Recursively merge two arrays.
     *
     * @param array<string, mixed> $array1
     * @param array<string, mixed> $array2
     * @return array<string, mixed>
     */
    public static function mergeRecursive(array $array1, array $array2): array
    {
        $merged = $array1;

        foreach ($array2 as $key => $value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                /** @var array<string, mixed> $target */
                $target = $merged[$key];
                $merged[$key] = self::mergeRecursive($target, $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }
}
