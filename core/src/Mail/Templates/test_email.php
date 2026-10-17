<?php

declare(strict_types=1);

/**
 * Variables available:
 * @var array<string, mixed> $data
 * @var array<string, mixed> $strings
 */

$subject = (string) ($strings['subject'] ?? 'Test email from BS CMS');
$name = htmlspecialchars((string) ($data['name'] ?? 'Administrator'), ENT_QUOTES, 'UTF-8');
$siteName = htmlspecialchars((string) ($data['site_name'] ?? 'BS CMS'), ENT_QUOTES, 'UTF-8');
$instructions = (string) ($strings['instructions'] ?? 'This is a test email sent from your BS CMS installation. If you are reading this, your email configuration is working properly!');
$footer = (string) ($strings['footer'] ?? 'Sent by BS CMS Email Subsystem.');
$sentAt = htmlspecialchars((string) ($data['sent_at'] ?? gmdate('Y-m-d H:i:s \U\T\C')), ENT_QUOTES, 'UTF-8');

$html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>{$subject}</title></head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f4f4f7; color: #333333; padding: 24px;">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; padding: 32px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
        <tr><td>
            <h2 style="margin-top: 0; color: #111827;">{$siteName}</h2>
            <p style="font-size: 16px; line-height: 1.5;">Hello {$name},</p>
            <p style="font-size: 16px; line-height: 1.5;">{$instructions}</p>
            <div style="background-color: #f3f4f6; border-radius: 6px; padding: 16px; margin: 24px 0; font-size: 14px; color: #374151;">
                <strong>Timestamp:</strong> {$sentAt}<br>
                <strong>System:</strong> {$siteName}
            </div>
            <p style="font-size: 14px; color: #6b7280; line-height: 1.5;">{$footer}</p>
        </td></tr>
    </table>
</body>
</html>
HTML;

$text = "Hello {$data['name']},\n\n{$instructions}\n\nTimestamp: {$sentAt}\nSystem: {$siteName}\n\n{$footer}\n\n- {$data['site_name']}";

return [
    'subject' => $subject,
    'html' => $html,
    'text' => $text,
];
