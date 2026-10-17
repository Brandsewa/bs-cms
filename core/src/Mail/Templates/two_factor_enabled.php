<?php

declare(strict_types=1);

/**
 * Variables available:
 * @var array<string, mixed> $data
 * @var array<string, mixed> $strings
 */

$subject = (string) ($strings['subject'] ?? 'Two-factor authentication enabled');
$siteName = htmlspecialchars((string) ($data['site_name'] ?? 'BS CMS'), ENT_QUOTES, 'UTF-8');
$instructions = (string) ($strings['instructions'] ?? 'Two-factor authentication has been enabled on your account.');
$footer = (string) ($strings['footer'] ?? 'Keep your recovery codes in a secure location.');

$html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>{$subject}</title></head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f4f4f7; color: #333333; padding: 24px;">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; padding: 32px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
        <tr><td>
            <h2 style="margin-top: 0; color: #111827;">{$siteName}</h2>
            <p style="font-size: 16px; line-height: 1.5;">Hello,</p>
            <p style="font-size: 16px; line-height: 1.5;">{$instructions}</p>
            <p style="font-size: 14px; color: #6b7280; line-height: 1.5;">{$footer}</p>
        </td></tr>
    </table>
</body>
</html>
HTML;

$text = "{$instructions}\n\n{$footer}\n\n- {$data['site_name']}";

return [
    'subject' => $subject,
    'html' => $html,
    'text' => $text,
];
