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
                $c->get(TemplateRenderer::class)
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
                $c->get(Connection::class)
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
    }

    public function boot(Container $container): void
    {
    }
}
