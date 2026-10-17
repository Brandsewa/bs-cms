<?php

declare(strict_types=1);

namespace BsCms\Security;

use RuntimeException;

/**
 * Libsodium authenticated symmetric encryption (crypto_secretbox).
 *
 * Supports key rotation via key ID prefix:
 * Encrypted payload format: "<key_id>$<nonce>$<ciphertext>" (base64 encoded pieces).
 */
final class Crypto
{
    private string $primaryKeyId;
    /** @var array<string, string> keyId => 32-byte binary key */
    private array $keys = [];

    /**
     * @param array<string, string> $keys Associative array of [keyId => base64_encoded_32_byte_key]
     * @param string $primaryKeyId The keyId to use for new encryptions
     */
    public function __construct(array $keys, string $primaryKeyId)
    {
        if (!extension_loaded('sodium')) {
            throw new RuntimeException('The sodium PHP extension is required for encryption.');
        }

        if (!isset($keys[$primaryKeyId])) {
            throw new RuntimeException("Primary key ID '{$primaryKeyId}' not found in provided keys.");
        }

        $this->primaryKeyId = $primaryKeyId;

        foreach ($keys as $id => $encodedKey) {
            $bin = base64_decode($encodedKey, true);
            if ($bin === false || strlen($bin) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new RuntimeException("Key ID '{$id}' is not a valid 32-byte base64 encoded key.");
            }
            $this->keys[$id] = $bin;
        }
    }

    /**
     * Helper to instantiate from a single base64 key or raw file.
     */
    public static function fromAppKey(string $base64Key, string $keyId = 'v1'): self
    {
        return new self([$keyId => trim($base64Key)], $keyId);
    }

    /**
     * Generate a new 32-byte cryptographically secure base64-encoded key.
     */
    public static function generateKey(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    /**
     * Encrypt a string using the primary key.
     */
    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = $this->keys[$this->primaryKeyId];

        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);

        return sprintf(
            '%s$%s$%s',
            $this->primaryKeyId,
            base64_encode($nonce),
            base64_encode($ciphertext)
        );
    }

    /**
     * Decrypt a ciphertext string, automatically resolving the key ID from the header.
     *
     * @throws RuntimeException if decryption or authentication fails, or key ID is unknown
     */
    public function decrypt(string $payload): string
    {
        $parts = explode('$', $payload);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed encrypted payload format.');
        }

        [$keyId, $nonceB64, $ciphertextB64] = $parts;

        if (!isset($this->keys[$keyId])) {
            throw new RuntimeException("Unknown key ID '{$keyId}' for decryption.");
        }

        $nonce = base64_decode($nonceB64, true);
        $ciphertext = base64_decode($ciphertextB64, true);

        if ($nonce === false || strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Invalid nonce in encrypted payload.');
        }

        if ($ciphertext === false) {
            throw new RuntimeException('Invalid ciphertext encoding.');
        }

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->keys[$keyId]);
        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed (MAC authentication error or corrupted data).');
        }

        return $plaintext;
    }

    /**
     * Check if a payload was encrypted with an older key ID and needs re-encryption.
     */
    public function needsRotation(string $payload): bool
    {
        $parts = explode('$', $payload, 2);
        return count($parts) === 2 && $parts[0] !== $this->primaryKeyId;
    }

    public function getPrimaryKeyId(): string
    {
        return $this->primaryKeyId;
    }
}
