<?php

declare(strict_types=1);

namespace BsCms\Auth;

use BsCms\Security\Crypto;
use InvalidArgumentException;
use RuntimeException;

/**
 * Two-Factor Authentication service implementing RFC 6238 TOTP (30s period, 6 digits, SHA-1).
 * Supports base32 encoding/decoding, otpauth URI generation, drift window (+-1 step),
 * replay protection, and libsodium secret encryption at rest.
 */
final class TwoFactorService
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    public const PERIOD = 30;
    public const DIGITS = 6;
    public const STEP_UP_TTL = 600; // 10 minutes

    /**
     * Generate a new random Base32 TOTP secret (default 20 bytes = 160 bits).
     *
     * @param int<1, max> $byteLength
     */
    public function generateSecret(int $byteLength = 20): string
    {
        $random = random_bytes($byteLength);
        return $this->base32Encode($random);
    }

    /**
     * Build standard otpauth URI for QR codes and authenticator apps.
     */
    public function getTotpUri(string $secret, string $accountName, string $issuer = 'BS CMS'): string
    {
        $encodedIssuer = rawurlencode($issuer);
        $encodedAccount = rawurlencode($accountName);
        $cleanSecret = strtoupper(trim(str_replace(' ', '', $secret)));

        return "otpauth://totp/{$encodedIssuer}:{$encodedAccount}?secret={$cleanSecret}&issuer={$encodedIssuer}&algorithm=SHA1&digits=" . self::DIGITS . "&period=" . self::PERIOD;
    }

    /**
     * Calculate TOTP code for a given secret and Unix timestamp.
     */
    public function getCode(string $secret, ?int $timestamp = null): string
    {
        $time = $timestamp ?? time();
        $step = (int) floor($time / self::PERIOD);
        return $this->computeCodeAtStep($secret, $step);
    }

    /**
     * Verify a 6-digit TOTP code within +-1 step window with replay protection.
     *
     * @param string $secret Base32 encoded TOTP secret
     * @param string $code 6-digit code submitted by user
     * @param int|null $lastUsedStep Step of last successfully verified code
     * @param int|null $timestamp Current Unix timestamp (defaults to time())
     * @return int|null Returns the verified step on success (for caller to record), or null on failure/replay
     */
    public function verifyCode(string $secret, string $code, ?int $lastUsedStep = null, ?int $timestamp = null): ?int
    {
        $cleanCode = trim(str_replace(' ', '', $code));
        if (strlen($cleanCode) !== self::DIGITS || !ctype_digit($cleanCode)) {
            return null;
        }

        $time = $timestamp ?? time();
        $currentStep = (int) floor($time / self::PERIOD);

        // Check window: current step, -1 step, +1 step
        for ($offset = -1; $offset <= 1; ++$offset) {
            $stepToCheck = $currentStep + $offset;

            // Replay protection: cannot reuse a step that was already consumed
            if ($lastUsedStep !== null && $stepToCheck <= $lastUsedStep) {
                continue;
            }

            $computed = $this->computeCodeAtStep($secret, $stepToCheck);
            if (hash_equals($computed, $cleanCode)) {
                return $stepToCheck;
            }
        }

        return null;
    }

    /**
     * Check if step-up re-authentication is still valid (within 10 minutes).
     */
    public function isStepUpValid(?int $mfaPassedAtTimestamp, int $validitySeconds = self::STEP_UP_TTL, ?int $now = null): bool
    {
        if ($mfaPassedAtTimestamp === null || $mfaPassedAtTimestamp <= 0) {
            return false;
        }
        $currentTime = $now ?? time();
        return ($currentTime - $mfaPassedAtTimestamp) <= $validitySeconds;
    }

    /**
     * Encrypt a TOTP secret using the application Crypto service.
     */
    public function encryptSecret(string $secret, Crypto $crypto): string
    {
        return $crypto->encrypt($secret);
    }

    /**
     * Decrypt an encrypted TOTP secret using the application Crypto service.
     */
    public function decryptSecret(string $encryptedSecret, Crypto $crypto): string
    {
        return $crypto->decrypt($encryptedSecret);
    }

    public function base32Encode(string $data): string
    {
        if ($data === '') {
            return '';
        }

        $binaryString = '';
        $length = strlen($data);
        for ($i = 0; $i < $length; ++$i) {
            $binaryString .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }

        $chunks = str_split($binaryString, 5);
        $encoded = '';
        foreach ($chunks as $chunk) {
            $padded = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $encoded .= self::BASE32_ALPHABET[(int) bindec($padded)];
        }

        return $encoded;
    }

    public function base32Decode(string $base32): string
    {
        $clean = strtoupper(trim(str_replace([' ', '=', '-'], '', $base32)));
        if ($clean === '') {
            return '';
        }

        $binaryString = '';
        $length = strlen($clean);
        for ($i = 0; $i < $length; ++$i) {
            $char = $clean[$i];
            $pos = strpos(self::BASE32_ALPHABET, $char);
            if ($pos === false) {
                throw new InvalidArgumentException("Invalid base32 character: '{$char}'.");
            }
            $binaryString .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $byteChunks = str_split($binaryString, 8);
        $decoded = '';
        foreach ($byteChunks as $byte) {
            if (strlen($byte) === 8) {
                $decoded .= chr((int) bindec($byte));
            }
        }

        return $decoded;
    }

    private function computeCodeAtStep(string $secret, int $step): string
    {
        $secretBytes = $this->base32Decode($secret);
        // Pack step counter as 64-bit big-endian integer
        $counter = pack('N*', 0, $step);
        $hash = hash_hmac('sha1', $counter, $secretBytes, true);

        // Dynamic truncation per RFC 4226 / RFC 6238
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        $otp = $binary % (10 ** self::DIGITS);
        return str_pad((string) $otp, self::DIGITS, '0', STR_PAD_LEFT);
    }
}
