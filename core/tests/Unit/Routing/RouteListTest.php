<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Routing;

use BsCms\Http\Controllers\AuthController;
use BsCms\Http\Controllers\AuditController;
use BsCms\Http\Controllers\BulkEntriesController;
use BsCms\Http\Controllers\CollectionsController;
use BsCms\Http\Controllers\EmailSettingsController;
use BsCms\Http\Controllers\EntriesController;
use BsCms\Http\Controllers\JobsController;
use BsCms\Http\Controllers\MeController;
use BsCms\Http\Controllers\NotificationsController;
use BsCms\Http\Controllers\OpenApiController;
use BsCms\Http\Controllers\PublicEntriesController;
use BsCms\Http\Controllers\RolesController;
use BsCms\Http\Controllers\SavedViewsController;
use BsCms\Http\Controllers\SingletonsController;
use BsCms\Http\Controllers\TokensController;
use BsCms\Http\Controllers\UsersController;
use BsCms\Http\Controllers\WebhooksController;
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

    public function testAllPhase3EndpointsAreRegistered(): void
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

        $collections = $this->createMock(CollectionsController::class);
        $entries = $this->createMock(EntriesController::class);
        $bulk = $this->createMock(BulkEntriesController::class);
        $singletons = $this->createMock(SingletonsController::class);
        $views = $this->createMock(SavedViewsController::class);
        $jobs = $this->createMock(JobsController::class);
        $webhooks = $this->createMock(WebhooksController::class);
        $publicEntries = $this->createMock(PublicEntriesController::class);
        $openApi = $this->createMock(OpenApiController::class);

        ApiRoutes::register(
            $routes,
            $auth,
            $me,
            $users,
            $roles,
            $tokens,
            $audit,
            $email,
            $notifications,
            $collections,
            $entries,
            $bulk,
            $singletons,
            $views,
            $jobs,
            $webhooks,
            $publicEntries,
            $openApi
        );

        $registered = [];
        foreach ($routes->getRoutes() as $r) {
            $registered[$r->getMethod() . ' ' . $r->getPattern()] = true;
        }

        $expectedPhase3Endpoints = [
            'GET /api/v1/collections',
            'POST /api/v1/collections',
            'GET /api/v1/collections/{handle}',
            'PUT /api/v1/collections/{handle}',
            'DELETE /api/v1/collections/{handle}',
            'POST /api/v1/schema/plan',
            'POST /api/v1/schema/apply/{change_id}',
            'GET /api/v1/schema/changes',
            'GET /api/v1/collections/{handle}/entries',
            'POST /api/v1/collections/{handle}/entries',
            'POST /api/v1/collections/{handle}/entries/bulk',
            'GET /api/v1/collections/{handle}/entries/{id}',
            'PUT /api/v1/collections/{handle}/entries/{id}',
            'PATCH /api/v1/collections/{handle}/entries/{id}',
            'DELETE /api/v1/collections/{handle}/entries/{id}',
            'POST /api/v1/collections/{handle}/entries/{id}/restore',
            'DELETE /api/v1/collections/{handle}/entries/{id}/purge',
            'POST /api/v1/collections/{handle}/entries/{id}/duplicate',
            'POST /api/v1/collections/{handle}/entries/{id}/publish',
            'POST /api/v1/collections/{handle}/entries/{id}/unpublish',
            'POST /api/v1/collections/{handle}/entries/{id}/archive',
            'POST /api/v1/collections/{handle}/entries/{id}/schedule',
            'POST /api/v1/collections/{handle}/entries/{id}/submit-review',
            'GET /api/v1/collections/{handle}/entries/{id}/compare',
            'GET /api/v1/collections/{handle}/entries/{id}/versions',
            'GET /api/v1/collections/{handle}/entries/{id}/versions/{version_id}',
            'POST /api/v1/collections/{handle}/entries/{id}/versions/{version_id}/restore',
            'GET /api/v1/singletons/{handle}',
            'PUT /api/v1/singletons/{handle}',
            'GET /api/v1/saved-views',
            'POST /api/v1/saved-views',
            'GET /api/v1/saved-views/{id}',
            'PUT /api/v1/saved-views/{id}',
            'DELETE /api/v1/saved-views/{id}',
            'GET /api/v1/jobs/{id}',
            'GET /api/v1/webhooks',
            'POST /api/v1/webhooks',
            'GET /api/v1/webhooks/{id}',
            'PATCH /api/v1/webhooks/{id}',
            'PUT /api/v1/webhooks/{id}',
            'DELETE /api/v1/webhooks/{id}',
            'POST /api/v1/webhooks/{id}/test',
            'GET /api/v1/webhooks/{id}/deliveries',
            'POST /api/v1/deliveries/{id:\d+}/retry',
            'GET /api/v1/public/collections/{handle}/entries',
            'GET /api/v1/public/collections/{handle}/entries/by-slug/{slug}',
            'GET /api/v1/openapi.json',
        ];

        foreach ($expectedPhase3Endpoints as $endpoint) {
            $this->assertArrayHasKey(
                $endpoint,
                $registered,
                "Expected Phase 3 route '{$endpoint}' was not registered in ApiRoutes."
            );
        }
    }
}
