<?php

declare(strict_types=1);

namespace BsCms\Routing;

use BsCms\Http\Controllers\AuthController;
use BsCms\Http\Controllers\AuditController;
use BsCms\Http\Controllers\EmailSettingsController;
use BsCms\Http\Controllers\MeController;
use BsCms\Http\Controllers\NotificationsController;
use BsCms\Http\Controllers\RolesController;
use BsCms\Http\Controllers\TokensController;
use BsCms\Http\Controllers\UsersController;

/**
 * Registers all REST API v1 endpoints for authentication, authorization, users, roles, audit, email, and notifications.
 */
final class ApiRoutes
{
    public static function register(
        RouteCollection $routes,
        AuthController $auth,
        MeController $me,
        UsersController $users,
        RolesController $roles,
        TokensController $tokens,
        AuditController $audit,
        EmailSettingsController $email,
        NotificationsController $notifications
    ): void {
        // 1. Auth endpoints
        $routes->post('/api/v1/auth/login', [$auth, 'login']);
        $routes->post('/api/v1/auth/logout', [$auth, 'logout']);
        $routes->post('/api/v1/auth/logout-all', [$auth, 'logoutAll']);
        $routes->get('/api/v1/auth/session', [$auth, 'session']);
        $routes->post('/api/v1/auth/2fa/challenge', [$auth, 'challenge2fa']);
        $routes->post('/api/v1/auth/password/forgot', [$auth, 'forgotPassword']);
        $routes->post('/api/v1/auth/password/reset', [$auth, 'resetPassword']);
        $routes->get('/api/v1/auth/invitations/{token}', [$auth, 'validateInvitation']);
        $routes->post('/api/v1/auth/invitations/{token}/accept', [$auth, 'acceptInvitation']);
        $routes->post('/api/v1/auth/email/verify', [$auth, 'verifyEmail']);
        $routes->post('/api/v1/auth/step-up', [$auth, 'stepUp']);

        // 2. Me endpoints
        $routes->get('/api/v1/me', [$me, 'getProfile']);
        $routes->patch('/api/v1/me', [$me, 'updateProfile']);
        $routes->post('/api/v1/me/password', [$me, 'changePassword']);
        $routes->post('/api/v1/me/email', [$me, 'requestEmailChange']);
        $routes->post('/api/v1/me/2fa/setup', [$me, 'setup2fa']);
        $routes->post('/api/v1/me/2fa/confirm', [$me, 'confirm2fa']);
        $routes->post('/api/v1/me/2fa/disable', [$me, 'disable2fa']);
        $routes->post('/api/v1/me/2fa/recovery-codes', [$me, 'regenerateRecoveryCodes']);
        $routes->get('/api/v1/me/sessions', [$me, 'getSessions']);
        $routes->delete('/api/v1/me/sessions/{id}', [$me, 'revokeSession']);
        $routes->get('/api/v1/me/permissions', [$me, 'getPermissions']);
        $routes->get('/api/v1/me/notification-preferences', [$me, 'getNotificationPreferences']);
        $routes->patch('/api/v1/me/notification-preferences', [$me, 'updateNotificationPreferences']);

        // 3. Users endpoints
        $routes->get('/api/v1/users', [$users, 'list']);
        $routes->post('/api/v1/users/invite', [$users, 'invite']);
        $routes->get('/api/v1/users/{id}', [$users, 'get']);
        $routes->patch('/api/v1/users/{id}', [$users, 'update']);
        $routes->post('/api/v1/users/{id}/suspend', [$users, 'suspend']);
        $routes->post('/api/v1/users/{id}/unsuspend', [$users, 'unsuspend']);
        $routes->post('/api/v1/users/{id}/unlock', [$users, 'unlock']);
        $routes->delete('/api/v1/users/{id}', [$users, 'delete']);
        $routes->post('/api/v1/users/bulk', [$users, 'bulk']);
        $routes->get('/api/v1/users/{id}/sessions', [$users, 'getSessions']);
        $routes->delete('/api/v1/users/{id}/sessions', [$users, 'deleteSessions']);

        // 4. Roles endpoints
        $routes->get('/api/v1/roles', [$roles, 'list']);
        $routes->post('/api/v1/roles', [$roles, 'create']);
        $routes->get('/api/v1/roles/{id}', [$roles, 'get']);
        $routes->patch('/api/v1/roles/{id}', [$roles, 'update']);
        $routes->delete('/api/v1/roles/{id}', [$roles, 'delete']);
        $routes->put('/api/v1/roles/{id}/permissions', [$roles, 'updatePermissions']);
        $routes->post('/api/v1/roles/{id}/clone', [$roles, 'clone']);

        // 5. Tokens endpoints
        $routes->get('/api/v1/tokens', [$tokens, 'list']);
        $routes->post('/api/v1/tokens', [$tokens, 'create']);
        $routes->delete('/api/v1/tokens/{id}', [$tokens, 'delete']);

        // 6. Audit endpoints
        $routes->get('/api/v1/audit', [$audit, 'list']);
        $routes->get('/api/v1/audit/{id:\d+}', [$audit, 'get']);

        // 7. Email endpoints
        $routes->get('/api/v1/settings/email', [$email, 'getSettings']);
        $routes->put('/api/v1/settings/email', [$email, 'updateSettings']);
        $routes->post('/api/v1/settings/email/test', [$email, 'testConnection']);
        $routes->post('/api/v1/settings/email/send-test', [$email, 'sendTestEmail']);
        $routes->get('/api/v1/email-log', [$email, 'listLogs']);
        $routes->post('/api/v1/email-log/{id:\d+}/resend', [$email, 'resend']);

        // 8. Notifications endpoints
        $routes->get('/api/v1/notifications', [$notifications, 'list']);
        $routes->post('/api/v1/notifications/read', [$notifications, 'markRead']);
        $routes->post('/api/v1/notifications/read-all', [$notifications, 'markAllRead']);
    }
}
