<?php

declare(strict_types=1);

namespace BsCms\Kernel;

use BsCms\Audit\AuditLogger;
use BsCms\Auth\ApiTokenService;
use BsCms\Auth\EmailVerificationService;
use BsCms\Auth\InvitationService;
use BsCms\Auth\LoginService;
use BsCms\Auth\PasswordHasher;
use BsCms\Auth\PasswordResetService;
use BsCms\Auth\RecoveryCodes;
use BsCms\Auth\SessionManager;
use BsCms\Auth\TwoFactorService;
use BsCms\Authz\Authorizer;
use BsCms\Authz\PermissionCompiler;
use BsCms\Database\Connection;
use BsCms\Http\Controllers\AuthController;
use BsCms\Http\Controllers\AuditController;
use BsCms\Http\Controllers\EmailSettingsController;
use BsCms\Http\Controllers\MeController;
use BsCms\Http\Controllers\NotificationsController;
use BsCms\Http\Controllers\RolesController;
use BsCms\Http\Controllers\TokensController;
use BsCms\Http\Controllers\UsersController;
use BsCms\Mail\Mailer;
use BsCms\Mail\MailerService;
use BsCms\Mail\NullTransport;
use BsCms\Mail\TemplateRenderer;
use BsCms\Notifications\NotificationService;
use BsCms\Security\Crypto;
use BsCms\Settings\SettingsRepository;
use BsCms\Users\RoleRepository;
use BsCms\Users\UserRepository;

/**
 * Registers all core singletons, repositories, domain services, and HTTP controllers into the Container.
 */
final class CoreServiceProvider implements ServiceProvider
{
    public function __construct(
        private readonly Application $app
    ) {
    }

    public function register(Container $container): void
    {
        $app = $this->app;

        // 1. Database Connection
        $container->singleton(Connection::class, function (Container $c): Connection {
            /** @var Config $config */
            $config = $c->get(Config::class);
            /** @var array{host?: string, port?: int, database?: string, username?: string, password?: string, prefix?: string} $dbConfig */
            $dbConfig = (array) $config->get('database', []);
            return new Connection($dbConfig);
        });

        // 2. Encryption / Crypto
        $container->singleton(Crypto::class, function (Container $c) use ($app): Crypto {
            /** @var Config $config */
            $config = $c->get(Config::class);
            $appKey = (string) $config->get('app.key', '');
            if ($appKey === '') {
                $keyFile = $app->storagePath('keys/app.key');
                if (is_file($keyFile)) {
                    $appKey = trim((string) file_get_contents($keyFile));
                }
            }
            $cleanKey = str_starts_with($appKey, 'base64:') ? substr($appKey, 7) : $appKey;
            return Crypto::fromAppKey($cleanKey !== '' ? $cleanKey : Crypto::generateKey());
        });

        // 3. Security & Hashing primitives
        $container->singleton(PasswordHasher::class, function (Container $c): PasswordHasher {
            return new PasswordHasher();
        });

        $container->singleton(TwoFactorService::class, function (Container $c): TwoFactorService {
            return new TwoFactorService();
        });

        $container->singleton(RecoveryCodes::class, function (Container $c): RecoveryCodes {
            return new RecoveryCodes();
        });

        // 4. Data Repositories
        $container->singleton(UserRepository::class, function (Container $c): UserRepository {
            return new UserRepository($c->get(Connection::class));
        });

        $container->singleton(RoleRepository::class, function (Container $c): RoleRepository {
            return new RoleRepository($c->get(Connection::class));
        });

        $container->singleton(SessionManager::class, function (Container $c): SessionManager {
            return new SessionManager($c->get(Connection::class));
        });

        $container->singleton(SettingsRepository::class, function (Container $c): SettingsRepository {
            return new SettingsRepository($c->get(Connection::class));
        });

        // 5. Authorization & Permissions
        $container->singleton(PermissionCompiler::class, function (Container $c): PermissionCompiler {
            return new PermissionCompiler($c->get(Connection::class));
        });

        $container->singleton(Authorizer::class, function (Container $c): Authorizer {
            return new Authorizer($c->get(Connection::class), $c->get(PermissionCompiler::class));
        });

        // 6. Audit & Notification
        $container->singleton(AuditLogger::class, function (Container $c): AuditLogger {
            return new AuditLogger($c->get(Connection::class));
        });

        $container->singleton(NotificationService::class, function (Container $c): NotificationService {
            return new NotificationService($c->get(Connection::class));
        });

        // 7. Mail & Templates
        $container->singleton(TemplateRenderer::class, function (Container $c) use ($app): TemplateRenderer {
            return new TemplateRenderer($app->basePath('src/Mail/Templates'));
        });

        $container->singleton(Mailer::class, function (Container $c): Mailer {
            return new MailerService($c->get(Connection::class), new NullTransport(), null, 20);
        });

        // 8. Auth Domain Services
        $container->singleton(ApiTokenService::class, function (Container $c): ApiTokenService {
            return new ApiTokenService($c->get(Connection::class), $c->get(AuditLogger::class));
        });

        $container->singleton(LoginService::class, function (Container $c): LoginService {
            return new LoginService(
                $c->get(Connection::class),
                $c->get(UserRepository::class),
                $c->get(PasswordHasher::class),
                $c->get(SessionManager::class),
                $c->get(TwoFactorService::class),
                $c->get(AuditLogger::class),
                $c->get(Mailer::class),
                $c->get(TemplateRenderer::class),
                $c->get(Crypto::class)
            );
        });

        $container->singleton(PasswordResetService::class, function (Container $c): PasswordResetService {
            /** @var Config $config */
            $config = $c->get(Config::class);
            $baseUrl = (string) $config->get('app.url', 'http://localhost');
            return new PasswordResetService(
                $c->get(Connection::class),
                $c->get(UserRepository::class),
                $c->get(PasswordHasher::class),
                $c->get(SessionManager::class),
                $c->get(AuditLogger::class),
                $c->get(Mailer::class),
                $c->get(TemplateRenderer::class),
                $baseUrl
            );
        });

        $container->singleton(InvitationService::class, function (Container $c): InvitationService {
            /** @var Config $config */
            $config = $c->get(Config::class);
            $baseUrl = (string) $config->get('app.url', 'http://localhost');
            return new InvitationService(
                $c->get(Connection::class),
                $c->get(UserRepository::class),
                $c->get(RoleRepository::class),
                $c->get(PasswordHasher::class),
                $c->get(AuditLogger::class),
                $c->get(Mailer::class),
                $c->get(TemplateRenderer::class),
                $baseUrl
            );
        });

        $container->singleton(EmailVerificationService::class, function (Container $c): EmailVerificationService {
            /** @var Config $config */
            $config = $c->get(Config::class);
            $baseUrl = (string) $config->get('app.url', 'http://localhost');
            return new EmailVerificationService(
                $c->get(Connection::class),
                $c->get(UserRepository::class),
                $c->get(AuditLogger::class),
                $c->get(Mailer::class),
                $c->get(TemplateRenderer::class),
                $baseUrl
            );
        });

        // 9. HTTP Controllers
        $container->singleton(AuthController::class, function (Container $c): AuthController {
            return new AuthController(
                $c->get(LoginService::class),
                $c->get(SessionManager::class),
                $c->get(PasswordResetService::class),
                $c->get(InvitationService::class),
                $c->get(EmailVerificationService::class),
                $c->get(UserRepository::class),
                $c->get(PasswordHasher::class),
                $c->get(TwoFactorService::class),
                $c->get(Connection::class),
                $c->get(Crypto::class)
            );
        });

        $container->singleton(MeController::class, function (Container $c): MeController {
            return new MeController(
                $c->get(UserRepository::class),
                $c->get(SessionManager::class),
                $c->get(PasswordHasher::class),
                $c->get(TwoFactorService::class),
                $c->get(EmailVerificationService::class),
                $c->get(AuditLogger::class),
                $c->get(PermissionCompiler::class),
                $c->get(Connection::class),
                $c->get(Crypto::class)
            );
        });

        $container->singleton(UsersController::class, function (Container $c): UsersController {
            return new UsersController(
                $c->get(UserRepository::class),
                $c->get(RoleRepository::class),
                $c->get(InvitationService::class),
                $c->get(SessionManager::class),
                $c->get(Authorizer::class),
                $c->get(AuditLogger::class)
            );
        });

        $container->singleton(RolesController::class, function (Container $c): RolesController {
            return new RolesController(
                $c->get(RoleRepository::class),
                $c->get(Authorizer::class),
                $c->get(AuditLogger::class)
            );
        });

        $container->singleton(TokensController::class, function (Container $c): TokensController {
            return new TokensController(
                $c->get(ApiTokenService::class)
            );
        });

        $container->singleton(AuditController::class, function (Container $c): AuditController {
            return new AuditController(
                $c->get(AuditLogger::class),
                $c->get(Authorizer::class)
            );
        });

        $container->singleton(EmailSettingsController::class, function (Container $c): EmailSettingsController {
            return new EmailSettingsController(
                $c->get(Connection::class),
                $c->get(Authorizer::class),
                $c->get(AuditLogger::class),
                $c->get(Mailer::class),
                $c->get(TemplateRenderer::class),
                $c->get(Crypto::class)
            );
        });

        $container->singleton(NotificationsController::class, function (Container $c): NotificationsController {
            return new NotificationsController(
                $c->get(NotificationService::class)
            );
        });

        // 10. Phase 3 Schema, Content & Operations Services
        $container->singleton(\BsCms\Schema\CollectionRegistry::class, function (Container $c) use ($app): \BsCms\Schema\CollectionRegistry {
            return new \BsCms\Schema\CollectionRegistry($c->get(Connection::class), $app->storagePath('cache/schema.php'));
        });

        $container->singleton(\BsCms\Schema\FieldTypeRegistry::class, function (): \BsCms\Schema\FieldTypeRegistry {
            return new \BsCms\Schema\FieldTypeRegistry();
        });

        $container->singleton(\BsCms\Schema\SchemaLock::class, function (Container $c): \BsCms\Schema\SchemaLock {
            return new \BsCms\Schema\SchemaLock($c->get(Connection::class));
        });

        $container->singleton(\BsCms\Schema\SchemaPlanner::class, function (Container $c): \BsCms\Schema\SchemaPlanner {
            return new \BsCms\Schema\SchemaPlanner(
                $c->get(Connection::class),
                $c->get(\BsCms\Schema\CollectionRegistry::class),
                $c->get(\BsCms\Schema\FieldTypeRegistry::class)
            );
        });

        $container->singleton(\BsCms\Schema\SchemaApplier::class, function (Container $c): \BsCms\Schema\SchemaApplier {
            return new \BsCms\Schema\SchemaApplier(
                $c->get(Connection::class),
                $c->get(\BsCms\Schema\CollectionRegistry::class),
                $c->get(\BsCms\Schema\SchemaLock::class)
            );
        });

        $container->singleton(\BsCms\Content\SlugService::class, function (Container $c): \BsCms\Content\SlugService {
            return new \BsCms\Content\SlugService($c->get(Connection::class));
        });

        $container->singleton(\BsCms\Content\HierarchyValidator::class, function (Container $c): \BsCms\Content\HierarchyValidator {
            return new \BsCms\Content\HierarchyValidator($c->get(Connection::class));
        });

        $container->singleton(\BsCms\Content\VersionManager::class, function (Container $c): \BsCms\Content\VersionManager {
            return new \BsCms\Content\VersionManager($c->get(Connection::class));
        });

        $container->singleton(\BsCms\Content\RelationManager::class, function (Container $c): \BsCms\Content\RelationManager {
            return new \BsCms\Content\RelationManager($c->get(Connection::class));
        });

        $container->singleton(\BsCms\Operations\ConfirmTokenService::class, function (Container $c): \BsCms\Operations\ConfirmTokenService {
            /** @var Config $config */
            $config = $c->get(Config::class);
            $appKey = (string) $config->get('app.key', '01234567890123456789012345678901');
            return new \BsCms\Operations\ConfirmTokenService($appKey, $c->get(Connection::class));
        });

        $container->singleton(\BsCms\Content\Query\QueryParser::class, function (): \BsCms\Content\Query\QueryParser {
            return new \BsCms\Content\Query\QueryParser();
        });

        $container->singleton(\BsCms\Content\EntryRepository::class, function (Container $c): \BsCms\Content\EntryRepository {
            return new \BsCms\Content\EntryRepository(
                $c->get(Connection::class),
                $c->get(\BsCms\Schema\CollectionRegistry::class),
                $c->get(\BsCms\Schema\FieldTypeRegistry::class),
                $c->get(\BsCms\Content\SlugService::class),
                $c->get(\BsCms\Content\HierarchyValidator::class),
                $c->get(\BsCms\Content\VersionManager::class),
                $c->get(\BsCms\Content\RelationManager::class),
                null,
                $c->has(\BsCms\Redirects\RedirectRepository::class) ? $c->get(\BsCms\Redirects\RedirectRepository::class) : null
            );
        });

        // 11. Phase 3 Controllers
        $container->singleton(\BsCms\Http\Controllers\CollectionsController::class, function (Container $c): \BsCms\Http\Controllers\CollectionsController {
            return new \BsCms\Http\Controllers\CollectionsController(
                $c->get(Connection::class),
                $c->get(\BsCms\Schema\CollectionRegistry::class),
                $c->get(\BsCms\Schema\SchemaPlanner::class),
                $c->get(\BsCms\Schema\SchemaApplier::class),
                $c->get(Authorizer::class),
                $c->get(\BsCms\Operations\ConfirmTokenService::class),
                $c->get(AuditLogger::class)
            );
        });

        $container->singleton(\BsCms\Security\HttpClient::class, function (Container $c): \BsCms\Security\HttpClient {
            return new \BsCms\Security\HttpClient();
        });

        $container->singleton(\BsCms\Events\EventBus::class, function (Container $c): \BsCms\Events\EventBus {
            return new \BsCms\Events\EventBus();
        });

        $container->singleton(\BsCms\Content\Cache\PublicResponseCache::class, function (Container $c) use ($app): \BsCms\Content\Cache\PublicResponseCache {
            return new \BsCms\Content\Cache\PublicResponseCache($app->basePath('storage/cache/public'));
        });

        $container->singleton(\BsCms\Webhooks\WebhookDispatcher::class, function (Container $c): \BsCms\Webhooks\WebhookDispatcher {
            return new \BsCms\Webhooks\WebhookDispatcher(
                $c->get(Connection::class),
                $c->get(Crypto::class),
                $c->get(\BsCms\Security\HttpClient::class),
                $c->get(AuditLogger::class)
            );
        });

        $container->singleton(\BsCms\Http\Controllers\EntriesController::class, function (Container $c): \BsCms\Http\Controllers\EntriesController {
            return new \BsCms\Http\Controllers\EntriesController(
                $c->get(\BsCms\Schema\CollectionRegistry::class),
                $c->get(\BsCms\Content\EntryRepository::class),
                $c->get(\BsCms\Content\Query\QueryParser::class),
                $c->get(\BsCms\Content\VersionManager::class),
                $c->get(Authorizer::class),
                $c->get(AuditLogger::class),
                $c->get(\BsCms\Operations\ConfirmTokenService::class),
                $c->get(\BsCms\Events\EventBus::class)
            );
        });

        $container->singleton(\BsCms\Http\Controllers\BulkEntriesController::class, function (Container $c): \BsCms\Http\Controllers\BulkEntriesController {
            return new \BsCms\Http\Controllers\BulkEntriesController(
                $c->get(Connection::class),
                $c->get(\BsCms\Schema\CollectionRegistry::class),
                $c->get(\BsCms\Content\EntryRepository::class),
                $c->get(Authorizer::class),
                $c->get(\BsCms\Operations\ConfirmTokenService::class),
                $c->get(AuditLogger::class),
                $c->get(\BsCms\Events\EventBus::class)
            );
        });

        $container->singleton(\BsCms\Http\Controllers\SingletonsController::class, function (Container $c): \BsCms\Http\Controllers\SingletonsController {
            return new \BsCms\Http\Controllers\SingletonsController(
                $c->get(Connection::class),
                $c->get(\BsCms\Schema\CollectionRegistry::class),
                $c->get(\BsCms\Content\EntryRepository::class),
                $c->get(Authorizer::class),
                $c->get(AuditLogger::class),
                $c->get(\BsCms\Operations\ConfirmTokenService::class)
            );
        });

        $container->singleton(\BsCms\Http\Controllers\SavedViewsController::class, function (Container $c): \BsCms\Http\Controllers\SavedViewsController {
            return new \BsCms\Http\Controllers\SavedViewsController(
                $c->get(Connection::class),
                $c->get(\BsCms\Schema\CollectionRegistry::class)
            );
        });

        $container->singleton(\BsCms\Http\Controllers\JobsController::class, function (Container $c): \BsCms\Http\Controllers\JobsController {
            return new \BsCms\Http\Controllers\JobsController(
                $c->get(Connection::class)
            );
        });

        $container->singleton(\BsCms\Http\Controllers\WebhooksController::class, function (Container $c): \BsCms\Http\Controllers\WebhooksController {
            return new \BsCms\Http\Controllers\WebhooksController(
                $c->get(Connection::class),
                $c->get(Crypto::class),
                $c->get(\BsCms\Webhooks\WebhookDispatcher::class),
                $c->get(Authorizer::class),
                $c->get(AuditLogger::class)
            );
        });

        $container->singleton(\BsCms\Http\Controllers\PublicEntriesController::class, function (Container $c): \BsCms\Http\Controllers\PublicEntriesController {
            return new \BsCms\Http\Controllers\PublicEntriesController(
                $c->get(Connection::class),
                $c->get(\BsCms\Schema\CollectionRegistry::class),
                $c->get(\BsCms\Content\EntryRepository::class),
                $c->get(\BsCms\Content\Query\QueryParser::class),
                $c->get(\BsCms\Content\Cache\PublicResponseCache::class),
                $c->has(\BsCms\Seo\SeoResolver::class) ? $c->get(\BsCms\Seo\SeoResolver::class) : null
            );
        });

        $container->singleton(\BsCms\OpenApi\OpenApiGenerator::class, function (Container $c): \BsCms\OpenApi\OpenApiGenerator {
            return new \BsCms\OpenApi\OpenApiGenerator(
                $c->get(\BsCms\Schema\CollectionRegistry::class),
                $c->get(\BsCms\Schema\FieldTypeRegistry::class),
                $c->get(Authorizer::class)
            );
        });

        $container->singleton(\BsCms\Http\Controllers\OpenApiController::class, function (Container $c): \BsCms\Http\Controllers\OpenApiController {
            return new \BsCms\Http\Controllers\OpenApiController(
                $c->get(\BsCms\OpenApi\OpenApiGenerator::class)
            );
        });

        // 11. Taxonomy Services & Controllers
        $container->singleton(\BsCms\Taxonomy\TaxonomyRepository::class, function (Container $c): \BsCms\Taxonomy\TaxonomyRepository {
            return new \BsCms\Taxonomy\TaxonomyRepository(
                $c->get(Connection::class)
            );
        });

        $container->singleton(\BsCms\Taxonomy\TermRepository::class, function (Container $c): \BsCms\Taxonomy\TermRepository {
            return new \BsCms\Taxonomy\TermRepository(
                $c->get(Connection::class),
                $c->has(\BsCms\Redirects\RedirectRepository::class) ? $c->get(\BsCms\Redirects\RedirectRepository::class) : null
            );
        });

        $container->singleton(\BsCms\Http\Controllers\TaxonomiesController::class, function (Container $c): \BsCms\Http\Controllers\TaxonomiesController {
            return new \BsCms\Http\Controllers\TaxonomiesController(
                $c->get(\BsCms\Taxonomy\TaxonomyRepository::class),
                $c->get(\BsCms\Taxonomy\TermRepository::class),
                $c->get(Authorizer::class),
                $c->has(\BsCms\Content\Cache\PublicResponseCache::class) ? $c->get(\BsCms\Content\Cache\PublicResponseCache::class) : null
            );
        });

        // 12. Media Services & Controllers
        $container->singleton(\BsCms\Media\MediaFolderRepository::class, function (Container $c): \BsCms\Media\MediaFolderRepository {
            return new \BsCms\Media\MediaFolderRepository($c->get(Connection::class));
        });

        $container->singleton(\BsCms\Media\MediaProcessor::class, function (Container $c) use ($app): \BsCms\Media\MediaProcessor {
            return new \BsCms\Media\MediaProcessor(
                $c->get(Connection::class),
                $app->basePath('public/media')
            );
        });

        $container->singleton(\BsCms\Media\MediaRepository::class, function (Container $c) use ($app): \BsCms\Media\MediaRepository {
            return new \BsCms\Media\MediaRepository(
                $c->get(Connection::class),
                $c->get(\BsCms\Media\MediaProcessor::class),
                $app->basePath('public/media')
            );
        });

        $container->singleton(\BsCms\Media\ChunkUploadManager::class, function (Container $c) use ($app): \BsCms\Media\ChunkUploadManager {
            return new \BsCms\Media\ChunkUploadManager(
                $app->basePath('storage/tmp')
            );
        });

        $container->singleton(\BsCms\Http\Controllers\MediaFoldersController::class, function (Container $c): \BsCms\Http\Controllers\MediaFoldersController {
            return new \BsCms\Http\Controllers\MediaFoldersController(
                $c->get(\BsCms\Media\MediaFolderRepository::class),
                $c->get(Authorizer::class)
            );
        });

        $container->singleton(\BsCms\Http\Controllers\MediaController::class, function (Container $c) use ($app): \BsCms\Http\Controllers\MediaController {
            return new \BsCms\Http\Controllers\MediaController(
                $c->get(\BsCms\Media\MediaRepository::class),
                $c->get(\BsCms\Media\ChunkUploadManager::class),
                $c->get(Authorizer::class),
                $app->basePath('storage/tmp')
            );
        });

        // 13. Redirects Services & Controllers
        $container->singleton(\BsCms\Redirects\RedirectRepository::class, function (Container $c): \BsCms\Redirects\RedirectRepository {
            return new \BsCms\Redirects\RedirectRepository($c->get(Connection::class));
        });

        $container->singleton(\BsCms\Http\Controllers\RedirectsController::class, function (Container $c): \BsCms\Http\Controllers\RedirectsController {
            return new \BsCms\Http\Controllers\RedirectsController(
                $c->get(\BsCms\Redirects\RedirectRepository::class),
                $c->get(Authorizer::class),
                $c->get(AuditLogger::class),
                $c->has(SettingsRepository::class) ? $c->get(SettingsRepository::class) : null,
                $c->has(Config::class) ? $c->get(Config::class) : null
            );
        });

        // 14. SEO Resolver & Controller
        $container->singleton(\BsCms\Seo\SeoResolver::class, function (Container $c): \BsCms\Seo\SeoResolver {
            /** @var Config $config */
            $config = $c->get(Config::class);
            $siteUrl = (string) $config->get('app.url', 'http://localhost');
            return new \BsCms\Seo\SeoResolver(
                $c->get(Connection::class),
                $c->has(\BsCms\Media\MediaRepository::class) ? $c->get(\BsCms\Media\MediaRepository::class) : null,
                $c->has(\BsCms\Taxonomy\TaxonomyRepository::class) ? $c->get(\BsCms\Taxonomy\TaxonomyRepository::class) : null,
                $c->has(\BsCms\Taxonomy\TermRepository::class) ? $c->get(\BsCms\Taxonomy\TermRepository::class) : null,
                $siteUrl
            );
        });

        $container->singleton(\BsCms\Http\Controllers\PublicSeoController::class, function (Container $c): \BsCms\Http\Controllers\PublicSeoController {
            return new \BsCms\Http\Controllers\PublicSeoController(
                $c->get(\BsCms\Seo\SeoResolver::class)
            );
        });

        // 15. Site Health Services & Controllers
        $container->singleton(\BsCms\Health\SiteHealthService::class, function (Container $c): \BsCms\Health\SiteHealthService {
            return new \BsCms\Health\SiteHealthService($c->get(Connection::class));
        });

        $container->singleton(\BsCms\Http\Controllers\SiteHealthController::class, function (Container $c): \BsCms\Http\Controllers\SiteHealthController {
            return new \BsCms\Http\Controllers\SiteHealthController(
                $c->get(\BsCms\Health\SiteHealthService::class),
                $c->get(Authorizer::class)
            );
        });

        // 16. Operations Dispatcher
        $container->singleton(\BsCms\Operations\Dispatcher::class, function (Container $c): \BsCms\Operations\Dispatcher {
            $dispatcher = new \BsCms\Operations\Dispatcher(
                $c->get(\BsCms\Operations\ConfirmTokenService::class),
                new \BsCms\Operations\JsonSchemaValidator(),
                $c->get(AuditLogger::class)
            );

            \BsCms\Operations\OperationsRegistrar::registerAll(
                $dispatcher,
                $c->get(\BsCms\Schema\CollectionRegistry::class),
                $c->get(\BsCms\Content\EntryRepository::class),
                $c->get(\BsCms\Content\VersionManager::class),
                $c->get(Authorizer::class),
                $c->get(Connection::class),
                $c->get(\BsCms\Taxonomy\TaxonomyRepository::class),
                $c->get(\BsCms\Taxonomy\TermRepository::class),
                $c->get(\BsCms\Media\MediaRepository::class),
                $c->get(\BsCms\Health\SiteHealthService::class),
                $c->get(\BsCms\Redirects\RedirectRepository::class),
                $c->get(AuditLogger::class),
                $c->has(SettingsRepository::class) ? $c->get(SettingsRepository::class) : null
            );

            return $dispatcher;
        });

        // 17. MCP and OAuth Controllers
        $container->singleton(\BsCms\OAuth\OAuthController::class, function (Container $c): \BsCms\OAuth\OAuthController {
            return new \BsCms\OAuth\OAuthController(
                $c->get(Connection::class),
                $c->get(\BsCms\Auth\PasswordHasher::class),
                $c->get(AuditLogger::class),
                $c->get(Authorizer::class),
                $c->has(Config::class) ? $c->get(Config::class) : null
            );
        });

        $container->singleton(\BsCms\Mcp\McpController::class, function (Container $c): \BsCms\Mcp\McpController {
            return new \BsCms\Mcp\McpController(
                $c->get(Connection::class),
                $c->get(\BsCms\OAuth\OAuthController::class),
                $c->get(ApiTokenService::class),
                $c->get(\BsCms\Operations\Dispatcher::class),
                $c->get(Authorizer::class),
                $c->get(AuditLogger::class),
                $c->has(\BsCms\Security\RateLimiter::class) ? $c->get(\BsCms\Security\RateLimiter::class) : null,
                $c->has(SettingsRepository::class) ? $c->get(SettingsRepository::class) : null,
                $c->has(Config::class) ? $c->get(Config::class) : null
            );
        });
    }

    public function boot(Container $container): void
    {
        if ($container->has(\BsCms\Events\EventBus::class)) {
            /** @var \BsCms\Events\EventBus $bus */
            $bus = $container->get(\BsCms\Events\EventBus::class);

            $bus->subscribeAll(function (\BsCms\Events\Event $e) use ($container) {
                // 1. Dispatch matching webhooks
                if ($container->has(\BsCms\Webhooks\WebhookDispatcher::class)) {
                    $container->get(\BsCms\Webhooks\WebhookDispatcher::class)->onEvent($e);
                }

                // 2. Invalidate public response cache for collection
                if ($container->has(\BsCms\Content\Cache\PublicResponseCache::class)) {
                    $payload = $e->payload();
                    $colKey = (string) ($payload['collection_key'] ?? $payload['collection'] ?? '');
                    if ($colKey !== '') {
                        $container->get(\BsCms\Content\Cache\PublicResponseCache::class)->invalidateCollection($colKey);
                    }
                }
            });
        }
    }
}
