<?php

declare(strict_types=1);

namespace BsCms\Webhooks;

final class WebhookSignature
{
    public const HEADER_NAME = 'X-BS-Signature';
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /**
     * Generate header string: "t=<timestamp>,v1=<sig>"
     */
    public static function sign(string $payloadJson, string $secret, ?int $timestamp = null): string
    {
        $ts = $timestamp ?? time();
        $signedPayload = "t={$ts}." . $payloadJson;
        $signature = hash_hmac('sha256', $signedPayload, $secret);

        return "t={$ts},v1={$signature}";
    }

    /**
     * Verify incoming signature header against payload and secret with replay protection.
     */
    public static function verify(
        string $header,
        string $payloadJson,
        string $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $currentTime = null
    ): bool {
        if ($header === '') {
            return false;
        }

        $elements = explode(',', $header);
        $timestamp = null;
        $signatures = [];

        foreach ($elements as $element) {
            $parts = explode('=', trim($element), 2);
            if (count($parts) === 2) {
                if ($parts[0] === 't') {
                    $timestamp = (int) $parts[1];
                } elseif ($parts[0] === 'v1') {
                    $signatures[] = $parts[1];
                }
            }
        }

        if ($timestamp === null || empty($signatures)) {
            return false;
        }

        $now = $currentTime ?? time();
        if (abs($now - $timestamp) > $toleranceSeconds) {
            // Replay or drifted timestamp
            return false;
        }

        $expectedSignature = hash_hmac('sha256', "t={$timestamp}." . $payloadJson, $secret);

        foreach ($signatures as $sig) {
            if (hash_equals($expectedSignature, $sig)) {
                return true;
            }
        }

        return false;
    }
}
