<?php

declare(strict_types=1);

namespace BsCms\Mail;

use InvalidArgumentException;

/**
 * Value object representing an email message with strict header-injection defense
 * and email address validation.
 */
final class MailMessage
{
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';

    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $htmlBody,
        public readonly string $textBody,
        public readonly string $template,
        public readonly ?string $fromEmail = null,
        public readonly ?string $fromName = null,
        public readonly ?string $replyTo = null,
        public readonly string $priority = self::PRIORITY_NORMAL,
        public readonly ?string $relatedUserId = null
    ) {
        $this->assertNoHeaderInjection($to, 'to');
        $this->assertNoHeaderInjection($subject, 'subject');

        if ($fromEmail !== null) {
            $this->assertNoHeaderInjection($fromEmail, 'fromEmail');
            $this->assertValidEmail($fromEmail, 'fromEmail');
        }

        if ($fromName !== null) {
            $this->assertNoHeaderInjection($fromName, 'fromName');
        }

        if ($replyTo !== null) {
            $this->assertNoHeaderInjection($replyTo, 'replyTo');
            $this->assertValidEmail($replyTo, 'replyTo');
        }

        $this->assertValidEmail($to, 'to');
    }

    private function assertNoHeaderInjection(string $value, string $field): void
    {
        if (preg_match("/[\r\n]/", $value)) {
            throw new InvalidArgumentException("Header injection attempt detected in '{$field}': carriage return or line feed.");
        }
    }

    private function assertValidEmail(string $email, string $field): void
    {
        if (strlen($email) > 190) {
            throw new InvalidArgumentException("Email address in '{$field}' exceeds maximum allowed length of 190 characters.");
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException("Invalid email format for '{$field}': '{$email}'.");
        }
    }
}
