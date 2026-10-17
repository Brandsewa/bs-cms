<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Mail;

use BsCms\Mail\MailMessage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MailMessageTest extends TestCase
{
    public function testValidMessageCreation(): void
    {
        $message = new MailMessage(
            to: 'user@example.com',
            subject: 'Welcome to BS CMS',
            htmlBody: '<h1>Welcome</h1>',
            textBody: 'Welcome',
            template: 'welcome',
            fromEmail: 'admin@example.com',
            fromName: 'Admin',
            replyTo: 'support@example.com'
        );

        $this->assertSame('user@example.com', $message->to);
        $this->assertSame('Welcome to BS CMS', $message->subject);
        $this->assertSame('admin@example.com', $message->fromEmail);
    }

    public function testHeaderInjectionInToAddressRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Header injection');

        new MailMessage(
            to: "user@example.com\r\nBcc: evil@attacker.com",
            subject: 'Test',
            htmlBody: 'Body',
            textBody: 'Body',
            template: 'test'
        );
    }

    public function testHeaderInjectionInSubjectRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Header injection');

        new MailMessage(
            to: 'user@example.com',
            subject: "Subject\nBcc: evil@attacker.com",
            htmlBody: 'Body',
            textBody: 'Body',
            template: 'test'
        );
    }

    public function testHeaderInjectionInFromNameRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Header injection');

        new MailMessage(
            to: 'user@example.com',
            subject: 'Subject',
            htmlBody: 'Body',
            textBody: 'Body',
            template: 'test',
            fromName: "Admin\r\nBcc: evil@attacker.com"
        );
    }

    public function testInvalidEmailFormatRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid email format');

        new MailMessage(
            to: 'not-an-email',
            subject: 'Subject',
            htmlBody: 'Body',
            textBody: 'Body',
            template: 'test'
        );
    }

    public function testExcessiveEmailLengthRejected(): void
    {
        $longEmail = str_repeat('a', 185) . '@example.com';
        $this->assertGreaterThan(190, strlen($longEmail));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds maximum allowed length');

        new MailMessage(
            to: $longEmail,
            subject: 'Subject',
            htmlBody: 'Body',
            textBody: 'Body',
            template: 'test'
        );
    }
}
