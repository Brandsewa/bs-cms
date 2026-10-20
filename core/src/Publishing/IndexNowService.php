<?php

declare(strict_types=1);

namespace BsCms\Publishing;

use BsCms\Database\Connection;
use BsCms\Security\HttpClient;
use BsCms\Support\Clock;
use BsCms\Support\Json;
use Throwable;

/**
 * Service for sending IndexNow batches to search engines (Bing, Yandex, etc.) on publish/update.
 * Logs execution in the `index_notifications` table.
 */
final class IndexNowService
{
    private const INDEXNOW_ENDPOINT = 'https://api.indexnow.org/indexnow';

    public function __construct(
        private readonly Connection $connection,
        private readonly HttpClient $httpClient
    ) {
    }

    /**
     * Submit changed URLs to IndexNow if key is configured.
     *
     * @param list<string> $urls
     * @param string $host Hostname (e.g. bskickstarter.com)
     * @param string|null $key IndexNow key
     */
    public function notify(array $urls, string $host, ?string $key): ?int
    {
        if (empty($urls) || empty($key)) {
            return null;
        }

        // Deduplicate and filter absolute URLs
        $cleanUrls = array_values(array_unique(array_filter($urls, static function (string $u): bool {
            return str_starts_with($u, 'http://') || str_starts_with($u, 'https://');
        })));

        if (empty($cleanUrls)) {
            return null;
        }

        $payload = [
            'host' => $host,
            'key' => $key,
            'keyLocation' => "https://{$host}/{$key}.txt",
            'urlList' => array_slice($cleanUrls, 0, 10000), // Max 10,000 per IndexNow RFC
        ];

        $encoded = Json::encode($payload);
        $status = 'failed';
        $responseCode = null;
        $error = null;

        try {
            $resp = $this->httpClient->post(
                self::INDEXNOW_ENDPOINT,
                $encoded,
                ['Content-Type' => 'application/json; charset=utf-8']
            );
            $responseCode = $resp['status'];
            if ($responseCode >= 200 && $responseCode < 300) {
                $status = 'sent';
            } else {
                $error = "HTTP {$responseCode}: " . substr($resp['body'], 0, 200);
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        // Log to index_notifications table
        $table = $this->connection->prefixTable('index_notifications');
        $now = Clock::sql(Clock::now());

        $stmt = $this->connection->getPdo()->prepare(
            "INSERT INTO `{$table}` (`engine`, `urls`, `status`, `response_code`, `error`, `created_at`)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            'indexnow',
            Json::encode($cleanUrls),
            $status,
            $responseCode,
            $error !== null ? substr($error, 0, 500) : null,
            $now,
        ]);

        return (int) $this->connection->getPdo()->lastInsertId();
    }
}
