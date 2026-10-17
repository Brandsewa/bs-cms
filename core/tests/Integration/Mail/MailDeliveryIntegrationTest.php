<?php

declare(strict_types=1);

namespace BsCms\Tests\Integration\Mail;

use BsCms\Database\Connection;
use BsCms\Database\Migrator;
use BsCms\Jobs\JobQueue;
use BsCms\Mail\LogTransport;
use BsCms\Mail\MailerService;
use BsCms\Mail\MailMessage;
use BsCms\Mail\NullTransport;
use BsCms\Mail\SendMailJob;
use BsCms\Mail\TransportInterface;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MailDeliveryIntegrationTest extends TestCase
{
    private Connection $connection;
    private string $dbName = '';
    private PDO $adminPdo;
    private string $tempLogDir;

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '';
        $this->dbName = 'bs_mail_' . bin2hex(random_bytes(4));

        try {
            $this->adminPdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $this->adminPdo->exec("CREATE DATABASE `{$this->dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            if (getenv('DB_HOST') !== false) {
                $this->fail('Database configured via DB_HOST but unreachable: ' . $e->getMessage());
            }
            $this->markTestSkipped('No local MySQL/MariaDB available.');
        }

        $this->connection = new Connection([
            'driver' => 'mysql',
            'host' => $host,
            'port' => $port,
            'database' => $this->dbName,
            'username' => $user,
            'password' => $pass,
            'charset' => 'utf8mb4',
            'prefix' => 'bs_',
        ]);

        $migrator = new Migrator($this->connection);
        $migrator->registerNamespace('core', dirname(__DIR__, 3) . '/migrations');
        $migrator->migrate();

        $this->tempLogDir = sys_get_temp_dir() . '/bs_mail_test_' . bin2hex(random_bytes(4));
        @mkdir($this->tempLogDir, 0750, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->adminPdo) && $this->dbName !== '') {
            $this->adminPdo->exec("DROP DATABASE IF EXISTS `{$this->dbName}`");
        }

        if (isset($this->tempLogDir) && is_dir($this->tempLogDir)) {
            $files = glob($this->tempLogDir . '/*');
            if ($files !== false) {
                foreach ($files as $f) {
                    @unlink($f);
                }
            }
            @rmdir($this->tempLogDir);
        }

        parent::tearDown();
    }

    public function testInlineDeliveryWithLogTransportRecordsEmailLog(): void
    {
        $transport = new LogTransport($this->tempLogDir);
        $mailer = new MailerService($this->connection, $transport);

        $msg = new MailMessage(
            to: 'recipient@example.com',
            subject: 'Test Subject',
            htmlBody: '<p>Hello</p>',
            textBody: 'Hello',
            template: 'test_email'
        );

        $sent = $mailer->send($msg, true);
        $this->assertTrue($sent);

        // Verify .eml file created
        $files = glob($this->tempLogDir . '/*.eml');
        $this->assertIsArray($files);
        $this->assertNotEmpty($files);
        $content = file_get_contents($files[0]);
        $this->assertStringContainsString('Subject: Test Subject', (string) $content);

        // Verify email_log DB record
        $pdo = $this->connection->getPdo();
        $table = $this->connection->prefixTable('email_log');
        $stmt = $pdo->query("SELECT * FROM `{$table}` WHERE `to_email` = 'recipient@example.com'");
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertSame('sent', $row['status']);
        $this->assertSame('test_email', $row['template']);
        $this->assertNotNull($row['sent_at']);
    }

    public function testPerRecipientHourlyRateLimitEnforced(): void
    {
        $transport = new NullTransport();
        $mailer = new MailerService($this->connection, $transport, hourlyLimit: 5);

        // Send 5 messages successfully
        for ($i = 0; $i < 5; $i++) {
            $msg = new MailMessage(
                to: 'limited@example.com',
                subject: "Message {$i}",
                htmlBody: 'Body',
                textBody: 'Body',
                template: 'notification'
            );
            $this->assertTrue($mailer->send($msg, true));
        }

        // 6th message should be rejected
        $msg6 = new MailMessage(
            to: 'limited@example.com',
            subject: 'Message 6',
            htmlBody: 'Body',
            textBody: 'Body',
            template: 'notification'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Per-recipient limit of 5 emails/hour exceeded');
        $mailer->send($msg6, true);
    }

    public function testSystemAlertBypassesHourlyRateLimit(): void
    {
        $transport = new NullTransport();
        $mailer = new MailerService($this->connection, $transport, hourlyLimit: 2);

        for ($i = 0; $i < 2; $i++) {
            $msg = new MailMessage(
                to: 'owner@example.com',
                subject: "Notice {$i}",
                htmlBody: 'Body',
                textBody: 'Body',
                template: 'notice'
            );
            $mailer->send($msg, true);
        }

        // 3rd message with system_alert template should succeed despite limit
        $alertMsg = new MailMessage(
            to: 'owner@example.com',
            subject: 'CRITICAL SYSTEM ALERT',
            htmlBody: 'Database disk full',
            textBody: 'Database disk full',
            template: 'system_alert'
        );

        $this->assertTrue($mailer->send($alertMsg, true));
    }

    public function testInlineFailureFallsBackToQueueAndSendMailJobWorks(): void
    {
        $failingTransport = new class () implements TransportInterface {
            public function send(MailMessage $message): bool
            {
                return false;
            }
            public function getLastError(): string
            {
                return 'Connection timeout to SMTP';
            }
            public function getName(): string
            {
                return 'failing_smtp';
            }
        };

        $queue = new JobQueue($this->connection);
        $mailer = new MailerService($this->connection, $failingTransport, $queue);

        $msg = new MailMessage(
            to: 'queued@example.com',
            subject: 'Queued Message',
            htmlBody: '<p>Queued</p>',
            textBody: 'Queued',
            template: 'password_reset'
        );

        // Attempt send high priority -> fails inline -> pushes to queue
        $result = $mailer->send($msg, true);
        $this->assertTrue($result);

        // Verify in DB that email_log status is 'queued'
        $pdo = $this->connection->getPdo();
        $logTable = $this->connection->prefixTable('email_log');
        $stmt = $pdo->query("SELECT * FROM `{$logTable}` WHERE `to_email` = 'queued@example.com'");
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertSame('queued', $row['status']);
        $this->assertStringContainsString('Connection timeout', (string) $row['error']);

        // Now verify SendMailJob processes it successfully with a working transport
        $workingTransport = new NullTransport();
        $job = new SendMailJob($this->connection, $workingTransport);

        $job->handle([
            'email_log_id' => $row['id'],
            'to' => $row['to_email'],
            'subject' => $row['subject'],
            'htmlBody' => '<p>Queued</p>',
            'textBody' => 'Queued',
            'template' => $row['template'],
        ]);

        // Verify email_log status changed to 'sent'
        $stmt2 = $pdo->prepare("SELECT status, sent_at FROM `{$logTable}` WHERE `id` = ?");
        $stmt2->execute([$row['id']]);
        $updatedRow = $stmt2->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($updatedRow);
        $this->assertSame('sent', $updatedRow['status']);
        $this->assertNotNull($updatedRow['sent_at']);
    }
}
