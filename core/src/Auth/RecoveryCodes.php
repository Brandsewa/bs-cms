<?php

declare(strict_types=1);

namespace BsCms\Auth;

/**
 * Handles generation and single-use consumption of emergency 2FA recovery codes.
 * Codes are presented once to the user; only SHA-256 hashes are stored at rest.
 */
final class RecoveryCodes
{
    public const DEFAULT_COUNT = 10;

    /**
     * Generate a new set of recovery codes.
     *
     * @return array{codes: list<string>, hashes: list<string>}
     */
    public function generate(int $count = self::DEFAULT_COUNT): array
    {
        $codes = [];
        $hashes = [];

        for ($i = 0; $i < $count; ++$i) {
            $raw = bin2hex(random_bytes(4)); // 8 hex characters
            $formatted = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
            $codes[] = $formatted;
            $hashes[] = $this->hashCode($formatted);
        }

        return [
            'codes' => $codes,
            'hashes' => $hashes,
        ];
    }

    /**
     * Verify and consume a recovery code against a list of stored SHA-256 hashes.
     *
     * @param string $submittedCode The plaintext code submitted by the user
     * @param list<string> $storedHashes Currently valid hashed codes for the user
     * @return list<string>|null Returns the updated remaining hashes on success, or null on failure
     */
    public function consume(string $submittedCode, array $storedHashes): ?array
    {
        $submittedHash = $this->hashCode($submittedCode);

        $index = array_search($submittedHash, $storedHashes, true);
        if ($index === false) {
            return null;
        }

        // Single-use: remove the matched hash
        unset($storedHashes[$index]);

        return array_values($storedHashes);
    }

    public function hashCode(string $code): string
    {
        $normalized = strtolower(trim(str_replace(['-', ' '], '', $code)));
        return hash('sha256', $normalized);
    }
}
