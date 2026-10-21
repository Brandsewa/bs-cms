<?php

declare(strict_types=1);

namespace BsCms\Routing;

use BsCms\Http\Controllers\AuthController;
use BsCms\Http\Controllers\AuditController;
use BsCms\Http\Controllers\BuildTokensController;
use BsCms\Http\Controllers\BulkEntriesController;
use BsCms\Http\Controllers\CollectionsController;
use BsCms\Http\Controllers\DashboardController;
use BsCms\Http\Controllers\AiController;
use BsCms\Http\Controllers\EmailSettingsController;
use BsCms\Http\Controllers\EntriesController;
use BsCms\Http\Controllers\JobsController;
use BsCms\Http\Controllers\MeController;
use BsCms\Http\Controllers\MediaController;
use BsCms\Http\Controllers\MediaFoldersController;
use BsCms\Http\Controllers\NotificationsController;
use BsCms\Http\Controllers\OpenApiController;
use BsCms\Http\Controllers\PendingChangesController;
use BsCms\Http\Controllers\PreviewController;
use BsCms\Http\Controllers\PublicEntriesController;
use BsCms\Http\Controllers\PublicSeoController;
use BsCms\Http\Controllers\PublishingController;
use BsCms\Http\Controllers\RedirectsController;
use BsCms\Http\Controllers\RolesController;
use BsCms\Http\Controllers\SavedViewsController;
use BsCms\Http\Controllers\SingletonsController;
use BsCms\Http\Controllers\SiteHealthController;
use BsCms\Http\Controllers\TaxonomiesController;
use BsCms\Http\Controllers\TokensController;
use BsCms\Http\Controllers\UsersController;
use BsCms\Http\Controllers\WebhooksController;
use BsCms\Mcp\McpController;
use BsCms\OAuth\OAuthController;

/**
 * Registers all REST API v1 endpoints for authentication, authorization, users, roles, audit, email, notifications, collections, entries, singletons, saved views, jobs, taxonomies, media, redirects, seo, site health, mcp, and oauth.
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
        NotificationsController $notifications,
        ?CollectionsController $collections = null,
        ?EntriesController $entries = null,
        ?BulkEntriesController $bulkEntries = null,
        ?SingletonsController $singletons = null,
        ?SavedViewsController $savedViews = null,
        ?JobsController $jobs = null,
        ?WebhooksController $webhooks = null,
        ?PublicEntriesController $publicEntries = null,
        ?OpenApiController $openApi = null,
        ?TaxonomiesController $taxonomies = null,
        ?MediaController $media = null,
        ?MediaFoldersController $mediaFolders = null,
        ?RedirectsController $redirects = null,
        ?PublicSeoController $publicSeo = null,
        ?SiteHealthController $siteHealth = null,
        ?McpController $mcp = null,
        ?OAuthController $oauth = null,
        ?PendingChangesController $pendingChanges = null,
        ?DashboardController $dashboard = null,
        ?AiController $ai = null,
        ?PublishingController $publishing = null,
        ?BuildTokensController $buildTokens = null,
        ?PreviewController $preview = null,
        // SPRINT-C BEGIN: Blocks Controller param
        ?\BsCms\Http\Controllers\BlocksController $blocks = null,
        ?\BsCms\Http\Controllers\CrawlerPolicyController $crawlerPolicy = null
        // SPRINT-C END: Blocks Controller param
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
        $routes->get('/api/v1/me/preferences', [$me, 'getPreferences']);
        $routes->patch('/api/v1/me/preferences', [$me, 'updatePreferences']);

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

        // 9. Collections & Schema endpoints
        if ($collections !== null) {
            $routes->get('/api/v1/collections', [$collections, 'list']);
            $routes->post('/api/v1/collections', [$collections, 'create']);
            $routes->get('/api/v1/collections/{handle}', [$collections, 'get']);
            $routes->put('/api/v1/collections/{handle}', [$collections, 'update']);
            $routes->delete('/api/v1/collections/{handle}', [$collections, 'delete']);
            $routes->post('/api/v1/schema/plan', [$collections, 'plan']);
            $routes->post('/api/v1/schema/apply/{change_id}', [$collections, 'applyChange']);
            $routes->get('/api/v1/schema/changes', [$collections, 'changes']);
        }

        // 10. Entries & Bulk endpoints
        if ($entries !== null) {
            $routes->get('/api/v1/collections/{handle}/counts', [$entries, 'counts']);
            $routes->get('/api/v1/collections/{handle}/entries', [$entries, 'list']);
            $routes->post('/api/v1/collections/{handle}/entries', [$entries, 'create']);
            if ($bulkEntries !== null) {
                $routes->post('/api/v1/collections/{handle}/entries/bulk', [$bulkEntries, 'bulk']);
            }
            $routes->get('/api/v1/collections/{handle}/entries/{id}', [$entries, 'get']);
            $routes->put('/api/v1/collections/{handle}/entries/{id}', [$entries, 'update']);
            $routes->patch('/api/v1/collections/{handle}/entries/{id}', [$entries, 'patch']);
            $routes->delete('/api/v1/collections/{handle}/entries/{id}', [$entries, 'delete']);
            $routes->post('/api/v1/collections/{handle}/entries/{id}/restore', [$entries, 'restore']);
            $routes->delete('/api/v1/collections/{handle}/entries/{id}/purge', [$entries, 'purge']);
            $routes->post('/api/v1/collections/{handle}/entries/{id}/duplicate', [$entries, 'duplicate']);
            $routes->post('/api/v1/collections/{handle}/entries/{id}/publish', [$entries, 'publish']);
            $routes->post('/api/v1/collections/{handle}/entries/{id}/unpublish', [$entries, 'unpublish']);
            $routes->post('/api/v1/collections/{handle}/entries/{id}/archive', [$entries, 'archive']);
            $routes->post('/api/v1/collections/{handle}/entries/{id}/schedule', [$entries, 'schedule']);
            $routes->post('/api/v1/collections/{handle}/entries/{id}/submit-review', [$entries, 'submitReview']);
            $routes->get('/api/v1/collections/{handle}/entries/{id}/compare', [$entries, 'compare']);
            $routes->get('/api/v1/collections/{handle}/entries/{id}/versions', [$entries, 'listVersions']);
            $routes->get('/api/v1/collections/{handle}/entries/{id}/versions/{version_id}', [$entries, 'getVersion']);
            $routes->post('/api/v1/collections/{handle}/entries/{id}/versions/{version_id}/restore', [$entries, 'restoreVersion']);
        }

        // 11. Singletons endpoints
        if ($singletons !== null) {
            $routes->get('/api/v1/singletons/{handle}', [$singletons, 'get']);
            $routes->put('/api/v1/singletons/{handle}', [$singletons, 'update']);
            $routes->patch('/api/v1/singletons/{handle}', [$singletons, 'update']);
        }

        // 12. Saved Views endpoints
        if ($savedViews !== null) {
            $routes->get('/api/v1/saved-views', [$savedViews, 'list']);
            $routes->post('/api/v1/saved-views', [$savedViews, 'create']);
            $routes->get('/api/v1/saved-views/{id}', [$savedViews, 'get']);
            $routes->put('/api/v1/saved-views/{id}', [$savedViews, 'update']);
            $routes->delete('/api/v1/saved-views/{id}', [$savedViews, 'delete']);
        }

        // 13. Jobs endpoints
        if ($jobs !== null) {
            $routes->get('/api/v1/jobs/{id}', [$jobs, 'get']);
        }

        // 14. Webhooks endpoints
        if ($webhooks !== null) {
            $routes->get('/api/v1/webhooks', [$webhooks, 'list']);
            $routes->post('/api/v1/webhooks', [$webhooks, 'create']);
            $routes->get('/api/v1/webhooks/{id}', [$webhooks, 'get']);
            $routes->patch('/api/v1/webhooks/{id}', [$webhooks, 'update']);
            $routes->put('/api/v1/webhooks/{id}', [$webhooks, 'update']);
            $routes->delete('/api/v1/webhooks/{id}', [$webhooks, 'delete']);
            $routes->post('/api/v1/webhooks/{id}/test', [$webhooks, 'test']);
            $routes->get('/api/v1/webhooks/{id}/deliveries', [$webhooks, 'deliveries']);
            $routes->post('/api/v1/deliveries/{id:\d+}/retry', [$webhooks, 'retry']);
        }

        // 15. Public Content endpoints
        if ($publicEntries !== null) {
            $routes->get('/api/v1/public/site', [$publicEntries, 'site']);
            $routes->get('/api/v1/public/collections/{handle}/entries', [$publicEntries, 'list']);
            $routes->get('/api/v1/public/collections/{handle}/entries/by-slug/{slug}', [$publicEntries, 'bySlug']);
        }

        // 16. OpenAPI Specification
        if ($openApi !== null) {
            $routes->get('/api/v1/openapi.json', [$openApi, 'spec']);
        }

        // 17. Taxonomy and Term endpoints
        if ($taxonomies !== null) {
            $routes->get('/api/v1/taxonomies', [$taxonomies, 'listTaxonomies']);
            $routes->post('/api/v1/taxonomies', [$taxonomies, 'createTaxonomy']);
            $routes->get('/api/v1/taxonomies/{handle}', [$taxonomies, 'getTaxonomy']);
            $routes->patch('/api/v1/taxonomies/{handle}', [$taxonomies, 'updateTaxonomy']);
            $routes->delete('/api/v1/taxonomies/{handle}', [$taxonomies, 'deleteTaxonomy']);

            $routes->get('/api/v1/taxonomies/{handle}/terms', [$taxonomies, 'listTerms']);
            $routes->post('/api/v1/taxonomies/{handle}/terms', [$taxonomies, 'createTerm']);
            $routes->get('/api/v1/taxonomies/{handle}/terms/{id}', [$taxonomies, 'getTerm']);
            $routes->patch('/api/v1/taxonomies/{handle}/terms/{id}', [$taxonomies, 'updateTerm']);
            $routes->delete('/api/v1/taxonomies/{handle}/terms/{id}', [$taxonomies, 'deleteTerm']);
            $routes->post('/api/v1/taxonomies/{handle}/terms/{id}/move', [$taxonomies, 'moveTerm']);
            $routes->post('/api/v1/taxonomies/{handle}/terms/{id}/merge', [$taxonomies, 'mergeTerm']);

            $routes->post('/api/v1/taxonomies/{handle}/terms/reorder', [$taxonomies, 'reorderTerms']);
            $routes->post('/api/v1/taxonomies/{handle}/terms/bulk', [$taxonomies, 'bulkTerms']);
            $routes->post('/api/v1/taxonomies/{handle}/attach', [$taxonomies, 'attachTaxonomy']);

            $routes->get('/api/v1/public/taxonomies/{handle}/terms', [$taxonomies, 'publicListTerms']);
        }

        // 18. Media and Folders endpoints
        if ($mediaFolders !== null) {
            $routes->get('/api/v1/media/folders', [$mediaFolders, 'list']);
            $routes->post('/api/v1/media/folders', [$mediaFolders, 'create']);
            $routes->patch('/api/v1/media/folders/{id}', [$mediaFolders, 'update']);
            $routes->delete('/api/v1/media/folders/{id}', [$mediaFolders, 'delete']);
            $routes->post('/api/v1/media/folders/{id}/move', [$mediaFolders, 'move']);
        }

        if ($media !== null) {
            $routes->get('/api/v1/media', [$media, 'list']);
            $routes->post('/api/v1/media', [$media, 'upload']);
            $routes->post('/api/v1/media/bulk', [$media, 'bulk']);
            $routes->post('/api/v1/media/chunks/init', [$media, 'initChunk']);
            $routes->post('/api/v1/media/chunks/{id}', [$media, 'uploadChunk']);
            $routes->get('/api/v1/media/chunks/{id}', [$media, 'chunkStatus']);
            $routes->post('/api/v1/media/chunks/{id}/assemble', [$media, 'assembleChunk']);
            $routes->get('/api/v1/media/{id}', [$media, 'get']);
            $routes->patch('/api/v1/media/{id}', [$media, 'update']);
            $routes->post('/api/v1/media/{id}/replace', [$media, 'replace']);
            $routes->delete('/api/v1/media/{id}', [$media, 'delete']);
            $routes->get('/api/v1/media/{id}/usage', [$media, 'getUsage']);
            $routes->get('/api/v1/media/{id}/file', [$media, 'streamFile']);
            $routes->get('/api/v1/public/media/{id}/file', [$media, 'streamFile']);
        }

        // 19. Redirects endpoints
        if ($redirects !== null) {
            $routes->get('/api/v1/redirects', [$redirects, 'list']);
            $routes->post('/api/v1/redirects', [$redirects, 'create']);
            $routes->post('/api/v1/redirects/bulk', [$redirects, 'bulk']);
            $routes->post('/api/v1/redirects/import', [$redirects, 'import']);
            $routes->get('/api/v1/redirects/export', [$redirects, 'export']);
            $routes->get('/api/v1/redirects/{id}', [$redirects, 'get']);
            $routes->patch('/api/v1/redirects/{id}', [$redirects, 'update']);
            $routes->delete('/api/v1/redirects/{id}', [$redirects, 'delete']);

            $routes->get('/api/v1/public/redirects', [$redirects, 'publicList']);
            $routes->get('/api/v1/public/redirects/match', [$redirects, 'match']);
        }

        // 20. Public SEO resolver endpoint
        if ($publicSeo !== null) {
            $routes->get('/api/v1/public/seo/resolve', [$publicSeo, 'resolve']);
        }

        // 21. Site Health endpoints
        if ($siteHealth !== null) {
            $routes->get('/api/v1/site-health', [$siteHealth, 'status']);
            $routes->post('/api/v1/site-health/run', [$siteHealth, 'run']);
        }

        // 22. MCP and OAuth endpoints
        if ($mcp !== null) {
            $routes->get('/mcp', [$mcp, 'handleGet']);
            $routes->post('/mcp', [$mcp, 'handlePost']);
            $routes->options('/mcp', [$mcp, 'handleOptions']);
        }

        if ($oauth !== null) {
            $routes->get('/.well-known/oauth-protected-resource', [$oauth, 'protectedResource']);
            $routes->options('/.well-known/oauth-protected-resource', [$oauth, 'optionsProtectedResource']);
            $routes->get('/.well-known/oauth-authorization-server', [$oauth, 'authorizationServer']);
            $routes->options('/.well-known/oauth-authorization-server', [$oauth, 'optionsAuthorizationServer']);
            $routes->post('/oauth/register', [$oauth, 'register']);
            $routes->options('/oauth/register', [$oauth, 'optionsRegister']);
            $routes->get('/oauth/authorize', [$oauth, 'authorizeGet']);
            $routes->post('/oauth/authorize', [$oauth, 'authorizePost']);
            $routes->post('/oauth/token', [$oauth, 'token']);
            $routes->options('/oauth/token', [$oauth, 'optionsToken']);
            $routes->post('/oauth/revoke', [$oauth, 'revoke']);

            // Grants API
            $routes->get('/api/v1/oauth/grants', [$oauth, 'listGrants']);
            $routes->delete('/api/v1/oauth/grants/{id}', [$oauth, 'revokeGrant']);
        }

        // 23. Pending Changes endpoints
        if ($pendingChanges !== null) {
            $routes->get('/api/v1/pending-changes', [$pendingChanges, 'list']);
            $routes->get('/api/v1/pending-changes/{id}', [$pendingChanges, 'get']);
            $routes->post('/api/v1/pending-changes/{id}/approve', [$pendingChanges, 'approve']);
            $routes->post('/api/v1/pending-changes/{id}/reject', [$pendingChanges, 'reject']);
            $routes->post('/api/v1/pending-changes/expire', [$pendingChanges, 'expire']);
        }

        // 24. Dashboard aggregate endpoint
        if ($dashboard !== null) {
            $routes->get('/api/v1/dashboard', [$dashboard, 'get']);
        }

        // 25. AI Kill switch & Management endpoints
        if ($ai !== null) {
            $routes->get('/api/v1/ai/status', [$ai, 'status']);
            $routes->post('/api/v1/ai/disconnect', [$ai, 'disconnect']);
            $routes->post('/api/v1/ai/reconnect', [$ai, 'reconnect']);
        }

        // 26. Publishing and Build Tokens endpoints
        if ($publishing !== null) {
            $routes->get('/api/v1/frontend/status', [$publishing, 'frontendStatus']);
            $routes->get('/api/v1/settings/publishing', [$publishing, 'getSettings']);
            $routes->put('/api/v1/settings/publishing', [$publishing, 'updateSettings']);
            $routes->post('/api/v1/settings/publishing/test', [$publishing, 'testDispatch']);
            $routes->get('/api/v1/deployments', [$publishing, 'listDeployments']);
            $routes->post('/api/v1/deployments/trigger', [$publishing, 'triggerDeploy']);
            $routes->post('/api/v1/deployments/{id}/status', [$publishing, 'updateDeploymentStatus']);
        }

        if ($buildTokens !== null) {
            $routes->get('/api/v1/tokens/build', [$buildTokens, 'index']);
            $routes->post('/api/v1/tokens/build', [$buildTokens, 'create']);
            $routes->delete('/api/v1/tokens/build/{id}', [$buildTokens, 'revoke']);
        }

        // 27. Preview Draft endpoints
        if ($preview !== null) {
            $routes->post('/api/v1/preview/token', [$preview, 'createToken']);
            $routes->delete('/api/v1/preview/token', [$preview, 'revokeToken']);
            $routes->get('/api/v1/preview/{collection}/{id}', [$preview, 'getDraft']);
        }

        // SPRINT-C BEGIN: Blocks endpoints
        if ($blocks !== null) {
            $routes->get('/api/v1/blocks', [$blocks, 'list']);
            $routes->get('/api/v1/blocks/{name}', [$blocks, 'get']);
        }
        if ($crawlerPolicy !== null) {
            $routes->get('/api/v1/crawler-policy', [$crawlerPolicy, 'getActive']);
            $routes->put('/api/v1/crawler-policy', [$crawlerPolicy, 'update']);
            $routes->get('/api/v1/crawler-policy/versions', [$crawlerPolicy, 'listVersions']);
            $routes->post('/api/v1/crawler-policy/rollback/{id}', [$crawlerPolicy, 'rollback']);
            $routes->post('/api/v1/crawler-policy/canary', [$crawlerPolicy, 'runCanary']);
            $routes->post('/api/v1/crawler-policy/indexnow', [$crawlerPolicy, 'submitIndexNow']);
        }
        // SPRINT-C END: Blocks endpoints
    }
}
