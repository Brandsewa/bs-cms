<?php

declare(strict_types=1);

namespace BsCms\Mail;

/**
 * Mailer interface for queuing and dispatching emails.
 */
interface Mailer
{
    /**
     * Send or queue an email message.
     *
     * @param MailMessage $message
     * @param bool $highPriority If true, attempts inline delivery first before queueing
     * @return bool True if successfully sent or queued
     */
    public function send(MailMessage $message, bool $highPriority = false): bool;
}
