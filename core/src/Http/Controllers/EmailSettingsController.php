<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Audit\AuditLogger;
use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Database\Connection;
use BsCms\Mail\Mailer;
use BsCms\Mail\MailMessage;
use BsCms\Mail\SmtpTransport;
use BsCms\Mail\TemplateRenderer;
use BsCms\Security\Crypto;
use BsCms\Support\Clock;
use BsCms\Support\Json;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Controller for SMTP email settings, delivery testing, and bs_email_log auditing.
 */
class EmailSettingsController extends BaseController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Authorizer $authorizer,
        private readonly AuditLogger $auditLogger,
        private readonly Mailer $mailer,
        private readonly TemplateRenderer $templates,
        private readonly ?Crypto $crypto = null
    ) {
    }

    public function getSettings(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('settings', 'email'));
        if ($permError !== null) {
            return $permError;
        }

        $config = $this->loadEmailConfig();

        return $this->json([
            'provider' => $config['provider'] ?? 'generic',
            'host' => $config['host'] ?? '',
            'port' => (int) ($config['port'] ?? 587),
            'encryption' => $config['encryption'] ?? 'tls',
            'username' => $config['username'] ?? '',
            'from_email' => $config['from_email'] ?? '',
            'from_name' => $config['from_name'] ?? 'BS CMS',
            'reply_to' => $config['reply_to'] ?? null,
            'has_password' => !empty($config['password']),
        ]);
    }

    public function updateSettings(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('settings', 'email'));
        if ($permError !== null) {
            return $permError;
        }

        $stepUpError = $this->requireStepUp($request);
        if ($stepUpError !== null) {
            return $stepUpError;
        }

        $body = $this->getBody($request);
        $current = $this->loadEmailConfig();

        $updated = [
            'provider' => (string) ($body['provider'] ?? ($current['provider'] ?? 'generic')),
            'host' => (string) ($body['host'] ?? ($current['host'] ?? '')),
            'port' => (int) ($body['port'] ?? ($current['port'] ?? 587)),
            'encryption' => (string) ($body['encryption'] ?? ($current['encryption'] ?? 'tls')),
            'username' => (string) ($body['username'] ?? ($current['username'] ?? '')),
            'from_email' => (string) ($body['from_email'] ?? ($current['from_email'] ?? '')),
            'from_name' => (string) ($body['from_name'] ?? ($current['from_name'] ?? 'BS CMS')),
            'reply_to' => isset($body['reply_to']) ? (string) $body['reply_to'] : ($current['reply_to'] ?? null),
            'password' => !empty($body['password']) ? (string) $body['password'] : ($current['password'] ?? ''),
        ];

        $this->saveEmailConfig($updated, $this->getActor($request)->id);

        $this->auditLogger->record(
            $this->getActor($request),
            'settings.email_updated',
            new Subject('settings', 'email'),
            "Updated email configuration (provider: {$updated['provider']})"
        );

        return $this->json(['status' => 'settings_updated']);
    }

    public function testConnection(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('settings', 'email'));
        if ($permError !== null) {
            return $permError;
        }

        $body = $this->getBody($request);
        $config = !empty($body) ? $body : $this->loadEmailConfig();

        $transport = new SmtpTransport($config, 5);
        $connected = $transport->testConnection();

        return $this->json([
            'connected' => $connected,
            'error' => $connected ? null : $transport->getLastError(),
        ]);
    }

    public function sendTestEmail(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('settings', 'email'));
        if ($permError !== null) {
            return $permError;
        }

        $body = $this->getBody($request);
        $recipient = (string) ($body['to'] ?? '');

        if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return $this->problem(422, 'Unprocessable Content', 'A valid recipient email address is required.');
        }

        $actor = $this->getActor($request);
        $tpl = $this->templates->render('test_email', [
            'name' => $actor->label,
            'site_name' => 'BS CMS',
        ]);

        $msg = new MailMessage(
            to: $recipient,
            subject: $tpl['subject'],
            htmlBody: $tpl['html'],
            textBody: $tpl['text'],
            template: 'test_email',
            priority: MailMessage::PRIORITY_HIGH,
            relatedUserId: $actor->id
        );

        $sent = $this->mailer->send($msg, true);

        return $this->json([
            'status' => $sent ? 'test_email_sent' : 'test_email_queued',
        ]);
    }

    public function listLogs(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('email_log', '*'));
        if ($permError !== null) {
            return $permError;
        }

        $params = $request->getQueryParams();
        $table = $this->connection->prefixTable('email_log');
        $pdo = $this->connection->getPdo();

        $where = [];
        $sqlParams = [];

        if (isset($params['to_email']) && is_string($params['to_email'])) {
            $where[] = '`to_email` = ?';
            $sqlParams[] = $params['to_email'];
        }
        if (isset($params['status']) && is_string($params['status'])) {
            $where[] = '`status` = ?';
            $sqlParams[] = $params['status'];
        }
        if (isset($params['template']) && is_string($params['template'])) {
            $where[] = '`template` = ?';
            $sqlParams[] = $params['template'];
        }
        if (isset($params['cursor']) && is_numeric($params['cursor'])) {
            $where[] = '`id` < ?';
            $sqlParams[] = (int) $params['cursor'];
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $limit = isset($params['limit']) ? min(100, max(1, (int) $params['limit'])) : 50;
        $fetchLimit = $limit + 1;

        $sql = "SELECT * FROM `{$table}` {$whereSql} ORDER BY `id` DESC LIMIT {$fetchLimit}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($sqlParams);
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $nextCursor = null;
        if (count($rows) > $limit) {
            array_pop($rows);
            $last = end($rows);
            $nextCursor = $last !== false ? (int) $last['id'] : null;
        }

        return $this->json([
            'items' => $rows,
            'next_cursor' => $nextCursor,
        ]);
    }

    public function resend(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('email_log', '*'));
        if ($permError !== null) {
            return $permError;
        }

        $id = (int) $request->getAttribute('id', '0');
        $table = $this->connection->prefixTable('email_log');
        $stmt = $this->connection->getPdo()->prepare("SELECT * FROM `{$table}` WHERE `id` = ?");
        $stmt->execute([$id]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return $this->problem(404, 'Not Found', 'Email log record not found.');
        }

        $template = (string) $row['template'];
        $tpl = $this->templates->render($template, [
            'name' => 'there',
            'site_name' => 'BS CMS',
            'action_url' => '#',
        ]);

        $msg = new MailMessage(
            to: (string) $row['to_email'],
            subject: (string) $row['subject'],
            htmlBody: $tpl['html'],
            textBody: $tpl['text'],
            template: $template,
            priority: MailMessage::PRIORITY_NORMAL,
            relatedUserId: $row['related_user_id'] !== null ? (string) $row['related_user_id'] : null
        );

        $this->mailer->send($msg, false);

        return $this->json(['status' => 'resend_queued']);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadEmailConfig(): array
    {
        $settingsTable = $this->connection->prefixTable('settings');
        $stmt = $this->connection->getPdo()->prepare("SELECT `value` FROM `{$settingsTable}` WHERE `key` = 'mail_settings'");
        $stmt->execute();
        $raw = $stmt->fetchColumn();

        if (!is_string($raw)) {
            return [];
        }

        $decoded = Json::decode($raw);
        if (!is_array($decoded)) {
            return [];
        }

        if (!empty($decoded['password']) && $this->crypto !== null) {
            try {
                $decoded['password'] = $this->crypto->decrypt((string) $decoded['password']);
            } catch (\Throwable) {
                // If decryption fails, keep raw or empty
            }
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function saveEmailConfig(array $config, string $actorId): void
    {
        if (!empty($config['password']) && $this->crypto !== null) {
            $config['password'] = $this->crypto->encrypt((string) $config['password']);
        }

        $settingsTable = $this->connection->prefixTable('settings');
        $now = Clock::sql(Clock::now());

        $stmt = $this->connection->getPdo()->prepare(
            "INSERT INTO `{$settingsTable}` (`key`, `value`, `is_secret`, `updated_at`, `updated_by`)
             VALUES ('mail_settings', ?, 1, ?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_at` = VALUES(`updated_at`), `updated_by` = VALUES(`updated_by`)"
        );
        $stmt->execute([
            Json::encode($config),
            $now,
            $actorId,
        ]);
    }
}
