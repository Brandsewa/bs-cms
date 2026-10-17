<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Mail;

use BsCms\Mail\TemplateRenderer;
use PHPUnit\Framework\TestCase;

final class TemplateRendererTest extends TestCase
{
    private TemplateRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new TemplateRenderer();
    }

    public function testRendersPasswordResetTemplate(): void
    {
        $result = $this->renderer->render('password_reset', [
            'name' => 'Alice',
            'action_url' => 'https://example.com/reset?token=xyz',
            'site_name' => 'Test CMS',
        ]);

        $this->assertArrayHasKey('subject', $result);
        $this->assertArrayHasKey('html', $result);
        $this->assertArrayHasKey('text', $result);

        $this->assertSame('Reset your password', $result['subject']);
        $this->assertStringContainsString('Alice', $result['html']);
        $this->assertStringContainsString('https://example.com/reset?token=xyz', $result['html']);
        $this->assertStringContainsString('https://example.com/reset?token=xyz', $result['text']);
    }

    public function testRendersInvitationTemplate(): void
    {
        $result = $this->renderer->render('invitation', [
            'name' => 'Bob',
            'inviter_name' => 'Alice Admin',
            'role_name' => 'Editor',
            'action_url' => 'https://example.com/invitation/accept?token=123',
            'site_name' => 'Test CMS',
        ]);

        $this->assertSame('You have been invited to BS CMS', $result['subject']);
        $this->assertStringContainsString('Bob', $result['html']);
        $this->assertStringContainsString('Alice Admin', $result['html']);
        $this->assertStringContainsString('Editor', $result['html']);
    }

    public function testRendersTestEmailTemplate(): void
    {
        $result = $this->renderer->render('test_email', [
            'name' => 'Super Admin',
            'site_name' => 'Test CMS',
        ]);

        $this->assertSame('Test email from BS CMS', $result['subject']);
        $this->assertStringContainsString('Super Admin', $result['html']);
        $this->assertStringContainsString('working properly', $result['html']);
    }
}
