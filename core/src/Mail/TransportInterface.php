<?php

declare(strict_types=1);

namespace BsCms\Mail;

/**
 * Transport contract for email delivery.
 */
interface TransportInterface
{
    /**
     * Deliver the message.
     */
    public function send(MailMessage $message): bool;

    /**
     * Get the last error message if delivery failed.
     */
    public function getLastError(): ?string;

    /**
     * Identifier name of this transport provider.
     */
    public function getName(): string;
}
