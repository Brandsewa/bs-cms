<?php

declare(strict_types=1);

namespace BsCms\Mail;

use BsCms\Database\Connection;
use BsCms\Jobs\Job;
use BsCms\Support\Clock;
use RuntimeException;

/**
 * Background job handler for sending queued emails.
 */
final class SendMailJob implements Job
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TransportInterface $transport
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void
    {
        $emailLogId = (int) ($payload['email_log_id'] ?? 0);
        $message = new MailMessage(
            to: (string) ($payload['to'] ?? ''),
            subject: (string) ($payload['subject'] ?? ''),
            htmlBody: (string) ($payload['htmlBody'] ?? ''),
            textBody: (string) ($payload['textBody'] ?? ''),
            template: (string) ($payload['template'] ?? 'generic'),
            fromEmail: isset($payload['fromEmail']) ? (string) $payload['fromEmail'] : null,
            fromName: isset($payload['fromName']) ? (string) $payload['fromName'] : null,
            replyTo: isset($payload['replyTo']) ? (string) $payload['replyTo'] : null,
            priority: (string) ($payload['priority'] ?? MailMessage::PRIORITY_NORMAL),
            relatedUserId: isset($payload['relatedUserId']) ? (string) $payload['relatedUserId'] : null
        );

        $pdo = $this->connection->getPdo();
        $emailLogTable = $this->connection->prefixTable('email_log');

        $sent = $this->transport->send($message);
        if ($sent) {
            if ($emailLogId > 0) {
                $now = Clock::sql(Clock::now());
                $stmt = $pdo->prepare("UPDATE `{$emailLogTable}` SET `status` = 'sent', `sent_at` = ?, `provider` = ?, `error` = NULL WHERE `id` = ?");
                $stmt->execute([$now, $this->transport->getName(), $emailLogId]);
            }
        } else {
            $error = $this->transport->getLastError() ?? 'Unknown transport error';
            if ($emailLogId > 0) {
                $stmt = $pdo->prepare("UPDATE `{$emailLogTable}` SET `error` = ? WHERE `id` = ?");
                $stmt->execute([substr($error, 0, 500), $emailLogId]);
            }
            throw new RuntimeException("Email delivery failed: {$error}");
        }
    }
}
