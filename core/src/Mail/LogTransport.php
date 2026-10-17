<?php

declare(strict_types=1);

namespace BsCms\Mail;

/**
 * Development and testing mail transport that writes RFC-2822 .eml files to disk.
 */
final class LogTransport implements TransportInterface
{
    private string $logDirectory;
    private ?string $lastError = null;

    public function __construct(string $logDirectory)
    {
        $this->logDirectory = rtrim(str_replace('\\', '/', $logDirectory), '/');
        if (!is_dir($this->logDirectory)) {
            @mkdir($this->logDirectory, 0750, true);
        }
    }

    public function send(MailMessage $message): bool
    {
        $date = date('r');
        $from = $message->fromEmail !== null
            ? ($message->fromName !== null ? "{$message->fromName} <{$message->fromEmail}>" : $message->fromEmail)
            : 'noreply@bscms.org';

        $boundary = '=_bscms_' . bin2hex(random_bytes(8));

        $eml = "Date: {$date}\r\n";
        $eml .= "From: {$from}\r\n";
        $eml .= "To: {$message->to}\r\n";
        $eml .= "Subject: {$message->subject}\r\n";
        if ($message->replyTo !== null) {
            $eml .= "Reply-To: {$message->replyTo}\r\n";
        }
        $eml .= "MIME-Version: 1.0\r\n";
        $eml .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n";

        // Plain text part
        $eml .= "--{$boundary}\r\n";
        $eml .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $eml .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $eml .= $message->textBody . "\r\n\r\n";

        // HTML part
        $eml .= "--{$boundary}\r\n";
        $eml .= "Content-Type: text/html; charset=UTF-8\r\n";
        $eml .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $eml .= $message->htmlBody . "\r\n\r\n";

        $eml .= "--{$boundary}--\r\n";

        $filename = $this->logDirectory . '/' . date('Y-m-d_His') . '_' . bin2hex(random_bytes(4)) . '.eml';

        if (@file_put_contents($filename, $eml) === false) {
            $this->lastError = "Failed to write .eml file to '{$filename}'.";
            return false;
        }

        return true;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getName(): string
    {
        return 'log';
    }
}
