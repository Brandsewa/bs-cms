<?php

declare(strict_types=1);

namespace BsCms\Security;

use RuntimeException;

/**
 * SSRF-safe outbound HTTP client.
 *
 * Enforces:
 * - Scheme restricted to http/https.
 * - DNS pre-resolution and validation against private, loopback, link-local, and reserved IP ranges (IPv4 & IPv6).
 * - IP pinning via CURLOPT_RESOLVE to prevent DNS rebinding attacks (TOCTOU).
 * - Redirect following (max 3 hops), where every redirect hop is independently DNS-resolved and validated.
 * - Strict timeout (default 5 s).
 * - Response body size cap (default 2 MB).
 */
final class HttpClient
{
    private int $timeoutSeconds;
    private int $maxBodyBytes;
    private int $maxRedirects;

    public function __construct(
        int $timeoutSeconds = 5,
        int $maxBodyBytes = 2 * 1024 * 1024,
        int $maxRedirects = 3
    ) {
        $this->timeoutSeconds = max(1, $timeoutSeconds);
        $this->maxBodyBytes = max(1024, $maxBodyBytes);
        $this->maxRedirects = max(0, $maxRedirects);
    }

    /**
     * Perform an SSRF-safe GET request.
     *
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function get(string $url, array $headers = []): array
    {
        return $this->request('GET', $url, $headers);
    }

    /**
     * Perform an SSRF-safe POST request.
     *
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function post(string $url, string $body, array $headers = []): array
    {
        return $this->request('POST', $url, $headers, $body);
    }

    /**
     * Execute HTTP request with manual hop-by-hop redirect verification and IP pinning.
     *
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $currentUrl = $url;
        $hops = 0;

        while (true) {
            $parsed = parse_url($currentUrl);
            if ($parsed === false || !isset($parsed['scheme'])) {
                throw new RuntimeException("Invalid URL: '{$currentUrl}'");
            }

            $scheme = strtolower($parsed['scheme']);
            if ($scheme !== 'http' && $scheme !== 'https') {
                throw new RuntimeException("Unsupported URL scheme '{$scheme}'. Only http and https are allowed.");
            }

            if (!isset($parsed['host'])) {
                throw new RuntimeException("Invalid URL: '{$currentUrl}' (missing host)");
            }

            $host = $parsed['host'];
            $port = $parsed['port'] ?? ($scheme === 'https' ? 443 : 80);

            // Step 1: Pre-resolve DNS and validate IP safety
            $resolvedIp = $this->resolveAndValidateHost($host);

            // Step 2: Pin the IP using CURLOPT_RESOLVE to prevent DNS rebinding
            $resolveEntry = sprintf('%s:%d:%s', $host, $port, $resolvedIp);

            $ch = curl_init();
            if ($ch === false) {
                throw new RuntimeException('Failed to initialize cURL.');
            }

            $responseHeaders = [];
            $responseBody = '';
            $bodyLen = 0;
            assert($currentUrl !== '');
            assert($method !== '');

            curl_setopt_array($ch, [
                CURLOPT_URL => $currentUrl,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RESOLVE => [$resolveEntry],
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => min(3, $this->timeoutSeconds),
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false, // We handle redirects manually to check every hop
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HEADERFUNCTION => function ($ch, string $headerLine) use (&$responseHeaders): int {
                    $trimmed = trim($headerLine);
                    if ($trimmed !== '' && str_contains($trimmed, ':')) {
                        [$name, $value] = explode(':', $trimmed, 2);
                        $responseHeaders[strtolower(trim($name))] = trim($value);
                    }
                    return strlen($headerLine);
                },
                CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$responseBody, &$bodyLen): int {
                    $chunkLen = strlen($chunk);
                    $bodyLen += $chunkLen;
                    if ($bodyLen > $this->maxBodyBytes) {
                        return 0; // abort transfer if max body cap is exceeded
                    }
                    $responseBody .= $chunk;
                    return $chunkLen;
                },
            ]);

            // Format request headers
            $formattedHeaders = [];
            foreach ($headers as $k => $v) {
                $formattedHeaders[] = "{$k}: {$v}";
            }
            if (!empty($formattedHeaders)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $formattedHeaders);
            }

            if ($body !== null && ($method === 'POST' || $method === 'PUT' || $method === 'PATCH')) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            $execResult = curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($execResult === false) {
                if ($bodyLen > $this->maxBodyBytes) {
                    throw new RuntimeException("Response body exceeded maximum allowed limit of {$this->maxBodyBytes} bytes.");
                }
                throw new RuntimeException("HTTP request to '{$currentUrl}' failed: {$curlError}");
            }

            // Check if status is redirect
            if (in_array($statusCode, [301, 302, 303, 307, 308], true)) {
                if ($hops >= $this->maxRedirects) {
                    throw new RuntimeException("Maximum redirects ({$this->maxRedirects}) exceeded.");
                }

                if (!isset($responseHeaders['location'])) {
                    throw new RuntimeException("Redirect status {$statusCode} received without Location header.");
                }

                $location = $responseHeaders['location'];
                // Resolve relative URLs if needed
                if (!str_starts_with($location, 'http://') && !str_starts_with($location, 'https://')) {
                    $base = sprintf('%s://%s%s', $scheme, $host, isset($parsed['port']) ? ':' . $parsed['port'] : '');
                    $location = rtrim($base, '/') . '/' . ltrim($location, '/');
                }

                $currentUrl = $location;
                ++$hops;
                // For 303, switch method to GET per HTTP specification
                if ($statusCode === 303) {
                    $method = 'GET';
                    $body = null;
                }
                continue;
            }

            return [
                'status' => (int) $statusCode,
                'headers' => $responseHeaders,
                'body' => $responseBody,
            ];
        }
    }

    /**
     * Resolve host to IP address and verify it is not in a private/reserved/loopback range.
     *
     * @return string Validated public IP address
     * @throws RuntimeException if DNS resolution fails or IP is disallowed
     */
    public function resolveAndValidateHost(string $host): string
    {
        // If host is already an IP address
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ip = $host;
        } else {
            // Resolve host to IPv4
            $records = dns_get_record($host, DNS_A);
            if ($records === false || empty($records)) {
                // Try AAAA for IPv6
                $records6 = dns_get_record($host, DNS_AAAA);
                if ($records6 === false || empty($records6)) {
                    throw new RuntimeException("Failed to resolve host '{$host}'.");
                }
                /** @var array{ipv6: string} $first6 */
                $first6 = $records6[0];
                $ip = $first6['ipv6'];
            } else {
                /** @var array{ip: string} $first */
                $first = $records[0];
                $ip = $first['ip'];
            }
        }

        $this->assertPublicIp($ip);

        return $ip;
    }

    /**
     * Assert that an IP is a public, routable IP address (not private, loopback, or link-local).
     */
    public function assertPublicIp(string $ip): void
    {
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

        if (!filter_var($ip, FILTER_VALIDATE_IP, $flags)) {
            throw new RuntimeException("SSRF security violation: IP address '{$ip}' belongs to a private, loopback, or reserved network.");
        }

        // Additional IPv4 link-local (169.254.0.0/16) and cloud metadata guard
        if (str_starts_with($ip, '169.254.') || str_starts_with($ip, '127.') || $ip === '0.0.0.0') {
            throw new RuntimeException("SSRF security violation: IP address '{$ip}' is restricted.");
        }

        // IPv6 loopback and link-local guard (::1, fe80::, etc.)
        $lower = strtolower($ip);
        if ($lower === '::1' || str_starts_with($lower, 'fe80:') || str_starts_with($lower, 'fc00:') || str_starts_with($lower, 'fd00:')) {
            throw new RuntimeException("SSRF security violation: IPv6 address '{$ip}' is restricted.");
        }
    }
}
