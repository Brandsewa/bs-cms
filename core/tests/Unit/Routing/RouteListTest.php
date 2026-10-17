<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Routing;

use BsCms\Http\Controllers\AuthController;
use BsCms\Http\Controllers\AuditController;
use BsCms\Http\Controllers\EmailSettingsController;
use BsCms\Http\Controllers\MeController;
use BsCms\Http\Controllers\NotificationsController;
use BsCms\Http\Controllers\RolesController;
use BsCms\Http\Controllers\TokensController;
use BsCms\Http\Controllers\UsersController;
use BsCms\Routing\ApiRoutes;
use BsCms\Routing\RouteCollection;
use PHPUnit\Framework\TestCase;

final class RouteListTest extends TestCase
{
    public function testAllPhase2EndpointsAreRegistered(): void
    {
        $routes = new RouteCollection();

        $auth = $this->createMock(AuthController::class);
        $me = $this->createMock(MeController::class);
        $users = $this->createMock(UsersController::class);
        $roles = $this->createMock(RolesController::class);
        $tokens = $this->createMock(TokensController::class);
        $audit = $this->createMock(AuditController::class);
        $email = $this->createMock(EmailSettingsController::class);
        $notifications = $this->createMock(NotificationsController::class);

        ApiRoutes::register($routes, $auth, $me, $users, $roles, $tokens, $audit, $email, $notifications);

        $registered = [];
        foreach ($routes->getRoutes() as $r) {
            $registered[$r->getMethod() . ' ' . $r->getPattern()] = true;
        }

        $expectedEndpoints = [
            // Auth
            'POST /api/v1/auth/login',
            'POST /api/v1/auth/logout',
            'POST /api/v1/auth/logout-all',
            'GET /api/v1/auth/session',
            'POST /api/v1/auth/2fa/challenge',
            'POST /api/v1/auth/password/forgot',
            'POST /api/v1/auth/password/reset',
            'GET /api/v1/auth/invitations/{token}',
            'POST /api/v1/auth/invitations/{token}/accept',
            'POST /api/v1/auth/email/verify',
            'POST /api/v1/auth/step-up',

            // Me
            'GET /api/v1/me',
            'PATCH /api/v1/me',
            'POST /api/v1/me/password',
            'POST /api/v1/me/email',
            'POST /api/v1/me/2fa/setup',
            'POST /api/v1/me/2fa/confirm',
            'POST /api/v1/me/2fa/disable',
            'POST /api/v1/me/2fa/recovery-codes',
            'GET /api/v1/me/sessions',
            'DELETE /api/v1/me/sessions/{id}',
            'GET /api/v1/me/permissions',
            'GET /api/v1/me/notification-preferences',
            'PATCH /api/v1/me/notification-preferences',

            // Users
            'GET /api/v1/users',
            'POST /api/v1/users/invite',
            'GET /api/v1/users/{id}',
            'PATCH /api/v1/users/{id}',
            'POST /api/v1/users/{id}/suspend',
            'POST /api/v1/users/{id}/unsuspend',
            'POST /api/v1/users/{id}/unlock',
            'DELETE /api/v1/users/{id}',
            'POST /api/v1/users/bulk',
            'GET /api/v1/users/{id}/sessions',
            'DELETE /api/v1/users/{id}/sessions',

            // Roles
            'GET /api/v1/roles',
            'POST /api/v1/roles',
            'GET /api/v1/roles/{id}',
            'PATCH /api/v1/roles/{id}',
            'DELETE /api/v1/roles/{id}',
            'PUT /api/v1/roles/{id}/permissions',
            'POST /api/v1/roles/{id}/clone',

            // Tokens
            'GET /api/v1/tokens',
            'POST /api/v1/tokens',
            'DELETE /api/v1/tokens/{id}',

            // Audit
            'GET /api/v1/audit',
            'GET /api/v1/audit/{id:\d+}',

            // Email
            'GET /api/v1/settings/email',
            'PUT /api/v1/settings/email',
            'POST /api/v1/settings/email/test',
            'POST /api/v1/settings/email/send-test',
            'GET /api/v1/email-log',
            'POST /api/v1/email-log/{id:\d+}/resend',

            // Notifications
            'GET /api/v1/notifications',
            'POST /api/v1/notifications/read',
            'POST /api/v1/notifications/read-all',
        ];

        foreach ($expectedEndpoints as $endpoint) {
            $this->assertArrayHasKey(
                $endpoint,
                $registered,
                "Expected route '{$endpoint}' was not registered in ApiRoutes."
            );
        }

        $this->assertCount(count($expectedEndpoints), $registered);
    }
}
