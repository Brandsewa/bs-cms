<?php

declare(strict_types=1);

/**
 * Variables available:
 * @var array<string, mixed> $data
 * @var array<string, mixed> $strings
 */

$subject = (string) ($strings['subject'] ?? 'You have been invited to BS CMS');
$actionUrl = htmlspecialchars((string) ($data['action_url'] ?? '#'), ENT_QUOTES, 'UTF-8');
$siteName = htmlspecialchars((string) ($data['site_name'] ?? 'BS CMS'), ENT_QUOTES, 'UTF-8');
$roleName = htmlspecialchars((string) ($data['role_name'] ?? 'Team Member'), ENT_QUOTES, 'UTF-8');
$instructions = (string) ($strings['instructions'] ?? 'You have been invited to join. Click below to accept.');
$buttonText = (string) ($strings['button_text'] ?? 'Accept Invitation');
$footer = (string) ($strings['footer'] ?? 'If you were not expecting this invitation, please contact your administrator.');

$name = htmlspecialchars((string) ($data['name'] ?? ''), ENT_QUOTES, 'UTF-8');
$inviter = htmlspecialchars((string) ($data['inviter_name'] ?? ''), ENT_QUOTES, 'UTF-8');
$inviterHtml = $inviter !== '' ? " by <strong>{$inviter}</strong>" : '';
$inviterText = !empty($data['inviter_name']) ? " by {$data['inviter_name']}" : '';
$greeting = $name !== '' ? "Hello {$name}," : 'Hello,';
$textGreeting = !empty($data['name']) ? "Hello {$data['name']}," : 'Hello,';

$html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>{$subject}</title></head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f4f4f7; color: #333333; padding: 24px;">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; padding: 32px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
        <tr><td>
            <h2 style="margin-top: 0; color: #111827;">{$siteName}</h2>
            <p style="font-size: 16px; line-height: 1.5;">{$greeting}</p>
            <p style="font-size: 16px; line-height: 1.5;">{$instructions}{$inviterHtml} Role: <strong>{$roleName}</strong>.</p>
            <p style="text-align: center; margin: 32px 0;">
                <a href="{$actionUrl}" style="background-color: #10b981; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-weight: bold; display: inline-block;">{$buttonText}</a>
            </p>
            <p style="font-size: 14px; color: #6b7280; line-height: 1.5;">{$footer}</p>
        </td></tr>
    </table>
</body>
</html>
HTML;

$text = "{$textGreeting}\n\n{$instructions}{$inviterText} Role: {$data['role_name']}.\n\n{$data['action_url']}\n\n{$footer}\n\n- {$data['site_name']}";

return [
    'subject' => $subject,
    'html' => $html,
    'text' => $text,
];
