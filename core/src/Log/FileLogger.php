<?php

declare(strict_types=1);

namespace BsCms\Log;

use BsCms\Support\Clock;
use BsCms\Support\Json;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

/**
 * PSR-3 daily JSON Lines file logger with automatic sensitive field redaction and 14-day retention.
 */
final class FileLogger extends AbstractLogger
{
    private string $logDirectory;
    private ?string $requestId = null;
    private int $retentionDays;

    public function __construct(string $logDirectory, int $retentionDays = 14)
    {
        $this->logDirectory = rtrim(str_replace('\\', '/', $logDirectory), '/');
        $this->retentionDays = max(1, $retentionDays);

        if (!is_dir($this->logDirectory)) {
            @mkdir($this->logDirectory, 0750, true);
        }
    }

    public function setRequestId(?string $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed $level
     * @param string|Stringable $message
     * @param array<string, mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $levelString = is_string($level) ? strtolower($level) : LogLevel::INFO;
        $now = Clock::now();

        // Automatically redact sensitive keys from context
        $cleanContext = Redactor::redact($context);
        if (!is_array($cleanContext)) {
            $cleanContext = ['data' => $cleanContext];
        }

        $logEntry = [
            'timestamp' => Clock::iso8601($now),
            'level' => $levelString,
            'message' => Redactor::redact((string) $message),
            'request_id' => $this->requestId ?? $context['request_id'] ?? null,
            'context' => $cleanContext,
        ];

        // Format as single-line JSON
        $line = Json::encode($logEntry) . "\n";

        $date = $now->format('Y-m-d');
        $logFile = $this->logDirectory . "/bs-{$date}.log";

        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);

        // Periodically prune logs (1% chance per write to avoid disk thrashing)
        if (random_int(1, 100) === 1) {
            $this->prune();
        }
    }

    /**
     * Prune log files older than the retention threshold.
     */
    public function prune(): int
    {
        $deleted = 0;
        $cutoff = time() - ($this->retentionDays * 86400);
        $files = glob($this->logDirectory . '/bs-*.log') ?: [];

        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                if (@unlink($file)) {
                    ++$deleted;
                }
            }
        }

        return $deleted;
    }
}
