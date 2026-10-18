<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Log;

use BsCms\Log\FileLogger;
use BsCms\Log\Redactor;
use PHPUnit\Framework\TestCase;

final class FileLoggerAndRedactorTest extends TestCase
{
    private string $tempLogDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempLogDir = sys_get_temp_dir() . '/bs_cms_test_logs_' . bin2hex(random_bytes(6));
        if (!is_dir($this->tempLogDir)) {
            mkdir($this->tempLogDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempLogDir . '/*');
        if ($files) {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
        @rmdir($this->tempLogDir);
        parent::tearDown();
    }

    public function testRedactorScrubsSensitiveKeysRecursively(): void
    {
        $input = [
            'username' => 'alice',
            'password' => 'supersecret123',
            'api_key' => 'live_abcdef123456',
            'user' => [
                'token' => 'jwt.token.here',
                'recovery_code' => 'xyz-123',
                'nested' => [
                    'authorization' => 'Basic dXNlcjpwYXNz',
                    'normal_field' => 'visible',
                ],
            ],
            'cookie' => 'session=abc',
        ];

        $cleaned = Redactor::redact($input);

        $this->assertSame('alice', $cleaned['username']);
        $this->assertSame(Redactor::REDACTED_PLACEHOLDER, $cleaned['password']);
        $this->assertSame(Redactor::REDACTED_PLACEHOLDER, $cleaned['api_key']);
        $this->assertSame(Redactor::REDACTED_PLACEHOLDER, $cleaned['user']['token']);
        $this->assertSame(Redactor::REDACTED_PLACEHOLDER, $cleaned['user']['recovery_code']);
        $this->assertSame(Redactor::REDACTED_PLACEHOLDER, $cleaned['user']['nested']['authorization']);
        $this->assertSame('visible', $cleaned['user']['nested']['normal_field']);
        $this->assertSame(Redactor::REDACTED_PLACEHOLDER, $cleaned['cookie']);
    }

    public function testRedactorScrubsBearerAndBasicTokensInStrings(): void
    {
        $message = 'Request failed with Bearer eyJhbGciOiJIUzI1NiJ9 and Basic YWRtaW46cGFzcw== headers';
        $cleaned = Redactor::redact($message);

        $this->assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9', $cleaned);
        $this->assertStringNotContainsString('YWRtaW46cGFzcw==', $cleaned);
        $this->assertStringContainsString('Bearer [REDACTED]', $cleaned);
        $this->assertStringContainsString('Basic [REDACTED]', $cleaned);
    }

    public function testFileLoggerWritesJsonLinesWithRequestIdAndRedaction(): void
    {
        $logger = new FileLogger($this->tempLogDir);
        $logger->setRequestId('req_123456789');

        $logger->info('User login successful', [
            'user_id' => '01JABCDEF1234567890ABCDEF1',
            'password' => 'secret_pass',
            'ip' => '127.0.0.1',
        ]);

        $files = glob($this->tempLogDir . '/bs-*.log');
        $this->assertIsArray($files);
        $this->assertCount(1, $files);

        $content = file_get_contents($files[0]);
        $this->assertIsString($content);
        $this->assertNotEmpty($content);

        $entry = json_decode(trim($content), true);
        $this->assertIsArray($entry);
        $this->assertSame('info', $entry['level']);
        $this->assertSame('User login successful', $entry['message']);
        $this->assertSame('req_123456789', $entry['request_id']);
        $this->assertSame('01JABCDEF1234567890ABCDEF1', $entry['context']['user_id']);
        $this->assertSame(Redactor::REDACTED_PLACEHOLDER, $entry['context']['password']);
        $this->assertNotEmpty($entry['timestamp']);
    }

    public function testFileLoggerPruneDeletesOldLogFiles(): void
    {
        $logger = new FileLogger($this->tempLogDir, 14);

        $oldFile = $this->tempLogDir . '/bs-2020-01-01.log';
        file_put_contents($oldFile, "old log line\n");
        // Backdate file modification time by 20 days
        touch($oldFile, time() - (20 * 86400));

        $newFile = $this->tempLogDir . '/bs-2026-01-01.log';
        file_put_contents($newFile, "new log line\n");
        touch($newFile, time() - (5 * 86400));

        $deleted = $logger->prune();
        $this->assertSame(1, $deleted);
        $this->assertFileDoesNotExist($oldFile);
        $this->assertFileExists($newFile);
    }

    public function testFileLoggerWritesToExplicitAppLogFileWithRequestId(): void
    {
        $explicitFile = $this->tempLogDir . '/app.log';
        $logger = new FileLogger($explicitFile);
        $logger->setRequestId('req_forced_500_xyz');

        $logger->error('Server error in EntriesController::list', [
            'exception' => 'PDOException: Table not found',
            'action' => 'EntriesController::list',
        ]);

        $this->assertFileExists($explicitFile);
        $content = file_get_contents($explicitFile);
        $this->assertIsString($content);
        $this->assertStringContainsString('req_forced_500_xyz', $content);
        $this->assertStringContainsString('Server error in EntriesController::list', $content);
        $this->assertStringContainsString('PDOException: Table not found', $content);
    }
}
