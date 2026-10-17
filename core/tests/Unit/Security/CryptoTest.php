<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Security;

use BsCms\Security\Crypto;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CryptoTest extends TestCase
{
    public function testGenerateKeyCreatesValidBase64Key(): void
    {
        $key = Crypto::generateKey();
        $raw = base64_decode($key, true);
        $this->assertNotFalse($raw);
        $this->assertSame(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen($raw));
    }

    public function testEncryptAndDecryptRoundTrip(): void
    {
        $key = Crypto::generateKey();
        $crypto = Crypto::fromAppKey($key, 'v1');

        $plaintext = 'Super secret database password or API token 123!';
        $encrypted = $crypto->encrypt($plaintext);

        $this->assertStringStartsWith('v1$', $encrypted);
        $this->assertNotSame($plaintext, $encrypted);

        $decrypted = $crypto->decrypt($encrypted);
        $this->assertSame($plaintext, $decrypted);
    }

    public function testKeyRotationAndNeedsRotation(): void
    {
        $keyV1 = Crypto::generateKey();
        $keyV2 = Crypto::generateKey();

        // System has both keys, with v2 as primary
        $crypto = new Crypto([
            'v1' => $keyV1,
            'v2' => $keyV2,
        ], 'v2');

        // Old payload encrypted with v1
        $oldCrypto = Crypto::fromAppKey($keyV1, 'v1');
        $oldEncrypted = $oldCrypto->encrypt('data from yesterday');

        $this->assertTrue($crypto->needsRotation($oldEncrypted));

        // Decryption succeeds with the multi-key instance
        $decrypted = $crypto->decrypt($oldEncrypted);
        $this->assertSame('data from yesterday', $decrypted);

        // Re-encrypting with current primary key
        $newEncrypted = $crypto->encrypt($decrypted);
        $this->assertStringStartsWith('v2$', $newEncrypted);
        $this->assertFalse($crypto->needsRotation($newEncrypted));
    }

    public function testTamperedPayloadThrowsException(): void
    {
        $key = Crypto::generateKey();
        $crypto = Crypto::fromAppKey($key);

        $encrypted = $crypto->encrypt('un-tampered text');
        $parts = explode('$', $encrypted);

        // Corrupt ciphertext
        $corruptCipher = base64_encode(base64_decode($parts[2], true) . 'extra');
        $tampered = "{$parts[0]}\${$parts[1]}\${$corruptCipher}";

        $this->expectException(RuntimeException::class);
        $crypto->decrypt($tampered);
    }
}
