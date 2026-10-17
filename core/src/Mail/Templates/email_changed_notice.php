<?php

declare(strict_types=1);

/**
 * Variables available:
 * @var array<string, mixed> $data
 * @var array<string, mixed> $strings
 */

$subject = (string) ($strings['subject'] ?? 'Security notice: Your email address was changed');
$siteName = htmlspecialchars((string) ($data['site_name'] ?? 'BS CMS'), ENT_QUOTES, 'UTF-8');
$newEmail = htmlspecialchars((string) ($data['new_email'] ?? ''), ENT_QUOTES, 'UTF-8');
$instructions = (string) ($strings['instructions'] ?? 'Your account email address was changed.');
$alert = (string) ($strings['alert'] ?? 'If you did not perform this change, contact your administrator immediately.');

$html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>{$subject}</title></head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f4f4f7; color: #333333; padding: 24px;">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; padding: 32px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
        <tr><td>
            <h2 style="margin-top: 0; color: #111827;">{$siteName}</h2>
            <p style="font-size: 16px; line-height: 1.5;">{$instructions} New email: <strong>{$newEmail}</strong>.</p>
            <div style="background-color: #fef2f2; border-left: 4px solid #ef4444; padding: 16px; margin: 24px 0; border-radius: 4px;">
                <p style="margin: 0; color: #991b1b; font-weight: bold;">{$alert}</p>
            </div>
        </td></tr>
    </table>
</body>
</html>
HTML;

$text = "{$instructions} New email: {$data['new_email']}.\n\n{$alert}\n\n- {$data['site_name']}";

return [
    'subject' => $subject,
    'html' => $html,
    'text' => $text,
];
