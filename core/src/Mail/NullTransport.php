<?php

declare(strict_types=1);

namespace BsCms\Mail;

/**
 * Discards all outgoing emails. Used in tests or when email is explicitly disabled.
 */
final class NullTransport implements TransportInterface
{
    public function send(MailMessage $message): bool
    {
        return true;
    }

    public function getLastError(): ?string
    {
        return null;
    }

    public function getName(): string
    {
        return 'null';
    }
}
