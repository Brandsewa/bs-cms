<?php

declare(strict_types=1);

namespace BsCms\Operations;

use BsCms\Database\Connection;
use BsCms\Operations\Exceptions\OperationException;
use Throwable;

/**
 * Service for generating, verifying, and single-use consuming two-step confirmation tokens.
 * Complies with Phase 3 Amendment 6:
 * HMAC-signed with the app key, bound to actor id + operation name + SHA-256 of canonical input,
 * single-use (recorded until expiry), 5-minute expiry.
 */
final class ConfirmTokenService
{
    /** @var array<string, bool> */
    private array $consumedTokens = [];

    public function __construct(
        private readonly string $appKey,
        private readonly ?Connection $connection = null
    ) {
    }

    /**
     * Generate an HMAC-signed confirmation token bound to actor, operation, and input.
     *
     * @param array<string, mixed> $input
     */
    public function generate(string $actorId, string $operationName, array $input, int $ttl = 300): string
    {
        $inputHash = hash('sha256', self::canonicalJson($input));
        $payload = [
            'actor_id' => $actorId,
            'operation' => $operationName,
            'input_hash' => $inputHash,
            'expires_at' => time() + $ttl,
            'nonce' => bin2hex(random_bytes(16)),
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $encoded = self::base64UrlEncode($json);
        $signature = hash_hmac('sha256', $encoded, $this->appKey);

        return $encoded . '.' . $signature;
    }

    /**
     * Validate and consume a confirmation token.
     * Rejects tampered, expired, mismatched actor/op/input, or previously consumed tokens.
     *
     * @param array<string, mixed> $input
     * @throws OperationException
     */
    public function validateAndConsume(string $token, string $actorId, string $operationName, array $input): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            throw new OperationException('Invalid confirmation token structure.');
        }

        [$encodedPayload, $signature] = $parts;
        $expectedSignature = hash_hmac('sha256', $encodedPayload, $this->appKey);

        if (!hash_equals($expectedSignature, $signature)) {
            throw new OperationException('Confirmation token signature mismatch (tampered).');
        }

        $decoded = self::base64UrlDecode($encodedPayload);
        if ($decoded === false) {
            throw new OperationException('Failed to decode confirmation token.');
        }

        try {
            /** @var mixed $rawPayload */
            $rawPayload = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new OperationException('Invalid confirmation token payload JSON.');
        }

        if (!is_array($rawPayload)) {
            throw new OperationException('Invalid confirmation token payload structure.');
        }

        $expiresAt = isset($rawPayload['expires_at']) && is_int($rawPayload['expires_at']) ? $rawPayload['expires_at'] : 0;
        if ($expiresAt < time()) {
            throw new OperationException('Confirmation token has expired.');
        }

        $tokenActorId = isset($rawPayload['actor_id']) && is_string($rawPayload['actor_id']) ? $rawPayload['actor_id'] : '';
        if ($tokenActorId !== $actorId) {
            throw new OperationException('Confirmation token was issued to a different user.');
        }

        $tokenOp = isset($rawPayload['operation']) && is_string($rawPayload['operation']) ? $rawPayload['operation'] : '';
        if ($tokenOp !== $operationName) {
            throw new OperationException('Confirmation token does not match operation.');
        }

        $tokenInputHash = isset($rawPayload['input_hash']) && is_string($rawPayload['input_hash']) ? $rawPayload['input_hash'] : '';
        $currentInputHash = hash('sha256', self::canonicalJson($input));
        if (!hash_equals($tokenInputHash, $currentInputHash)) {
            throw new OperationException('Confirmation token does not match operation input parameters.');
        }

        // Single-use check: verify not replayed and mark consumed
        $tokenHash = hash('sha256', $token);

        if (isset($this->consumedTokens[$tokenHash])) {
            throw new OperationException('Confirmation token has already been used.');
        }

        if ($this->connection !== null) {
            $table = $this->connection->prefixTable('rate_limits');
            $bucket = 'confirm_used:' . $tokenHash;

            try {
                $pdo = $this->connection->getPdo();
                $check = $pdo->prepare("SELECT `hits` FROM `{$table}` WHERE `bucket` = ?");
                $check->execute([$bucket]);
                if ($check->fetchColumn() !== false) {
                    throw new OperationException('Confirmation token has already been used.');
                }

                $windowStart = date('Y-m-d H:i:s', $expiresAt);
                $insert = $pdo->prepare("INSERT INTO `{$table}` (`bucket`, `window_start`, `hits`) VALUES (?, ?, 1)");
                $insert->execute([$bucket, $windowStart]);
            } catch (OperationException $oe) {
                throw $oe;
            } catch (Throwable $e) {
                // If unique/duplicate constraint hit
                throw new OperationException('Confirmation token has already been used.');
            }
        }

        $this->consumedTokens[$tokenHash] = true;
        return true;
    }

    /**
     * Compute deterministic canonical JSON by recursively sorting all array keys.
     *
     * @param array<string, mixed> $data
     */
    public static function canonicalJson(array $data): string
    {
        self::ksortRecursive($data);
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $encoded !== false ? $encoded : '';
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function ksortRecursive(array &$data): void
    {
        ksort($data);
        foreach ($data as &$value) {
            if (is_array($value)) {
                self::ksortRecursive($value);
            }
        }
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string|false
    {
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}
