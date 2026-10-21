<?php

declare(strict_types=1);

namespace BsCms\CrawlerPolicy;

use BsCms\Database\Connection;

final class IndexNowService
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Submit changed URLs to IndexNow (Bing / participating search engines) and log notification.
     *
     * @param list<string> $urls
     * @param string $host e.g. "example.com"
     * @param string $key IndexNow key
     * @return array{success: bool, status_code: int|null, count: int}
     */
    public function submit(array $urls, string $host, string $key): array
    {
        if (empty($urls) || $key === '') {
            return ['success' => false, 'status_code' => null, 'count' => 0];
        }

        $payload = [
            'host' => $host,
            'key' => $key,
            'keyLocation' => "https://{$host}/{$key}.txt",
            'urlList' => array_values(array_unique($urls)),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $endpoint = 'https://api.indexnow.org/IndexNow';

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json; charset=utf-8\r\n" .
                            "Content-Length: " . strlen($json) . "\r\n",
                'content' => $json,
                'timeout' => 8,
                'ignore_errors' => true,
            ],
        ]);

        $status = 'failed';
        $code = null;
        $error = null;

        $res = @file_get_contents($endpoint, false, $ctx);
        if (isset($http_response_header)) {
            $statusLine = $http_response_header[0] ?? '';
            if (preg_match('/HTTP\/\S+\s+(\d+)/', $statusLine, $m)) {
                $code = (int) $m[1];
                if ($code === 200 || $code === 202) {
                    $status = 'sent';
                } else {
                    $error = "IndexNow returned HTTP {$code}: " . substr((string) $res, 0, 200);
                }
            }
        } else {
            $error = 'Network or timeout error contacting api.indexnow.org';
        }

        // Log notification to index_notifications table
        $table = $this->connection->prefixTable('index_notifications');
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare("INSERT INTO `{$table}` (`engine`, `urls`, `status`, `response_code`, `error`, `created_at`) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            'IndexNow',
            json_encode($urls),
            $status,
            $code,
            $error,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
        ]);

        return [
            'success' => $status === 'sent',
            'status_code' => $code,
            'count' => count($urls),
        ];
    }
}
