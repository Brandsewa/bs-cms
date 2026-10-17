<?php

declare(strict_types=1);

namespace BsCms\Mail;

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;
use Throwable;

/**
 * SMTP transport powered by PHPMailer with provider presets and connection testing.
 */
final class SmtpTransport implements TransportInterface
{
    public const PRESET_RESEND = 'resend';
    public const PRESET_ZEPTOMAIL = 'zeptomail';
    public const PRESET_ZOHO = 'zoho_mail';
    public const PRESET_GENERIC = 'generic';

    private ?string $lastError = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config,
        private readonly int $timeout = 5
    ) {
    }

    public function send(MailMessage $message): bool
    {
        $mailer = $this->createMailer();

        try {
            $fromEmail = $message->fromEmail ?? (string) ($this->config['from_email'] ?? 'noreply@bscms.org');
            $fromName = $message->fromName ?? (string) ($this->config['from_name'] ?? 'BS CMS');

            $mailer->setFrom($fromEmail, $fromName);
            $mailer->addAddress($message->to);

            if ($message->replyTo !== null) {
                $mailer->addReplyTo($message->replyTo);
            }

            $mailer->Subject = $message->subject;
            $mailer->isHTML(true);
            $mailer->Body = $message->htmlBody;
            $mailer->AltBody = $message->textBody;

            $sent = $mailer->send();
            if (!$sent) {
                $this->lastError = $mailer->ErrorInfo;
                return false;
            }

            return true;
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * Test SMTP connection without sending an email.
     */
    public function testConnection(): bool
    {
        $mailer = $this->createMailer();

        try {
            $connected = $mailer->smtpConnect();
            if ($connected) {
                $mailer->smtpClose();
                return true;
            }
            $this->lastError = $mailer->ErrorInfo !== '' ? $mailer->ErrorInfo : 'Failed to connect to SMTP server.';
            return false;
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getName(): string
    {
        return (string) ($this->config['provider'] ?? 'smtp');
    }

    private function createMailer(): PHPMailer
    {
        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->SMTPAuth = true;
        $mailer->CharSet = 'UTF-8';
        $mailer->Timeout = $this->timeout;

        $host = (string) ($this->config['host'] ?? '127.0.0.1');
        $port = (int) ($this->config['port'] ?? 587);
        $username = (string) ($this->config['username'] ?? '');
        $password = (string) ($this->config['password'] ?? '');
        $encryption = (string) ($this->config['encryption'] ?? 'tls');

        $mailer->Host = $host;
        $mailer->Port = $port;
        $mailer->Username = $username;
        $mailer->Password = $password;

        if ($encryption === 'ssl' || $encryption === 'smtps' || $port === 465) {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls' || $encryption === 'starttls' || $port === 587) {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mailer->SMTPSecure = '';
            $mailer->SMTPAutoTLS = false;
        }

        return $mailer;
    }
}
