<?php

declare(strict_types=1);

namespace BsCms\Mail;

use BsCms\Database\Connection;
use BsCms\Jobs\JobQueue;
use BsCms\Support\Clock;
use RuntimeException;

/**
 * MailerService: coordinates mail delivery, per-recipient rate limiting, inline attempts
 * for high-priority messages, background job queueing, and audit logging to bs_email_log.
 */
final class MailerService implements Mailer
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TransportInterface $transport,
        private readonly ?JobQueue $queue = null,
        private readonly int $hourlyLimit = 20
    ) {
    }

    public function send(MailMessage $message, bool $highPriority = false): bool
    {
        $pdo = $this->connection->getPdo();
        $emailLogTable = $this->connection->prefixTable('email_log');
        $now = Clock::sql(Clock::now());

        // 1. Per-recipient rate limit check (except system alerts to owners)
        if ($message->template !== 'system_alert') {
            $oneHourAgo = Clock::sql(Clock::now()->modify('-1 hour'));
            $checkStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM `{$emailLogTable}` WHERE `to_email` = ? AND `created_at` >= ? AND `status` != 'failed'"
            );
            $checkStmt->execute([$message->to, $oneHourAgo]);
            $recentCount = (int) $checkStmt->fetchColumn();

            if ($recentCount >= $this->hourlyLimit) {
                $err = "Per-recipient limit of {$this->hourlyLimit} emails/hour exceeded.";
                $insertStmt = $pdo->prepare(
                    "INSERT INTO `{$emailLogTable}` (`to_email`, `template`, `subject`, `status`, `provider`, `error`, `related_user_id`, `created_at`)
                     VALUES (?, ?, ?, 'failed', ?, ?, ?, ?)"
                );
                $insertStmt->execute([
                    $message->to,
                    $message->template,
                    $message->subject,
                    $this->transport->getName(),
                    $err,
                    $message->relatedUserId,
                    $now,
                ]);

                throw new RuntimeException("Cannot send email to '{$message->to}': {$err}");
            }
        }

        // 2. High priority inline attempt (password reset, invitation, test email)
        if ($highPriority || $this->queue === null) {
            $sent = $this->transport->send($message);
            if ($sent) {
                $insertStmt = $pdo->prepare(
                    "INSERT INTO `{$emailLogTable}` (`to_email`, `template`, `subject`, `status`, `provider`, `error`, `related_user_id`, `created_at`, `sent_at`)
                     VALUES (?, ?, ?, 'sent', ?, NULL, ?, ?, ?)"
                );
                $insertStmt->execute([
                    $message->to,
                    $message->template,
                    $message->subject,
                    $this->transport->getName(),
                    $message->relatedUserId,
                    $now,
                    $now,
                ]);

                return true;
            }

            // Inline attempt failed. If no queue, record failure and return false.
            if ($this->queue === null) {
                $err = $this->transport->getLastError() ?? 'Inline delivery failed and no queue configured';
                $insertStmt = $pdo->prepare(
                    "INSERT INTO `{$emailLogTable}` (`to_email`, `template`, `subject`, `status`, `provider`, `error`, `related_user_id`, `created_at`)
                     VALUES (?, ?, ?, 'failed', ?, ?, ?, ?)"
                );
                $insertStmt->execute([
                    $message->to,
                    $message->template,
                    $message->subject,
                    $this->transport->getName(),
                    substr($err, 0, 500),
                    $message->relatedUserId,
                    $now,
                ]);

                return false;
            }
        }

        // 3. Queue for background delivery
        $inlineError = $this->transport->getLastError();
        $errorNotice = $inlineError !== null ? substr("Inline attempt failed: {$inlineError}", 0, 500) : null;

        $insertStmt = $pdo->prepare(
            "INSERT INTO `{$emailLogTable}` (`to_email`, `template`, `subject`, `status`, `provider`, `error`, `related_user_id`, `created_at`)
             VALUES (?, ?, ?, 'queued', ?, ?, ?, ?)"
        );
        $insertStmt->execute([
            $message->to,
            $message->template,
            $message->subject,
            $this->transport->getName(),
            $errorNotice,
            $message->relatedUserId,
            $now,
        ]);

        $logId = (int) $pdo->lastInsertId();

        $this->queue->push(
            SendMailJob::class,
            [
                'email_log_id' => $logId,
                'to' => $message->to,
                'subject' => $message->subject,
                'htmlBody' => $message->htmlBody,
                'textBody' => $message->textBody,
                'template' => $message->template,
                'fromEmail' => $message->fromEmail,
                'fromName' => $message->fromName,
                'replyTo' => $message->replyTo,
                'priority' => $message->priority,
                'relatedUserId' => $message->relatedUserId,
            ],
            null,
            'default',
            5
        );

        return true;
    }
}
