<?php

declare(strict_types=1);

namespace BsCms\Tests\Integration\Authz;

use BsCms\Audit\AuditLogger;
use BsCms\Auth\ApiTokenService;
use BsCms\Auth\SessionManager;
use BsCms\Auth\SystemRoles;
use BsCms\Authz\Authorizer;
use BsCms\Authz\PermissionCompiler;
use BsCms\Content\Cache\PublicResponseCache;
use BsCms\Content\EntryRepository;
use BsCms\Content\HierarchyValidator;
use BsCms\Content\Query\QueryParser;
use BsCms\Content\RelationManager;
use BsCms\Content\SlugService;
use BsCms\Content\VersionManager;
use BsCms\Database\Connection;
use BsCms\Database\Migrator;
use BsCms\Http\Controllers\CollectionsController;
use BsCms\Http\Controllers\EntriesController;
use BsCms\Http\Controllers\MediaController;
use BsCms\Http\Controllers\MediaFoldersController;
use BsCms\Http\Controllers\PublicEntriesController;
use BsCms\Http\Controllers\TaxonomiesController;
use BsCms\Http\Kernel;
use BsCms\Http\Middleware\AuthenticateMiddleware;
use BsCms\Http\Middleware\ErrorHandlerMiddleware;
use BsCms\Http\Middleware\JsonBodyMiddleware;
use BsCms\Http\Middleware\RequestIdMiddleware;
use BsCms\Http\Middleware\SessionMiddleware;
use BsCms\Kernel\Environment;
use BsCms\Media\ChunkUploadManager;
use BsCms\Media\MediaProcessor;
use BsCms\Media\MediaFolderRepository;
use BsCms\Media\MediaRepository;
use BsCms\Operations\ConfirmTokenService;
use BsCms\Routing\ApiRoutes;
use BsCms\Routing\RouteCollection;
use BsCms\Routing\Router;
use BsCms\Schema\CollectionRegistry;
use BsCms\Schema\FieldTypeRegistry;
use BsCms\Schema\SchemaApplier;
use BsCms\Schema\SchemaLock;
use BsCms\Schema\SchemaPlanner;
use BsCms\Security\Ulid;
use BsCms\Taxonomy\TaxonomyRepository;
use BsCms\Taxonomy\TermRepository;
use Nyholm\Psr7\ServerRequest;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class RoleMatrixHttpIntegrationTest extends TestCase
{
    private Connection $connection;
    private string $dbName = '';
    private ?PDO $adminPdo = null;
    private Kernel $kernel;
    private string $tempDir;

    private TaxonomyRepository $taxonomies;
    private TermRepository $terms;
    private MediaFolderRepository $folders;
    private MediaRepository $media;
    private ApiTokenService $apiTokenService;

    /** @var array<string, string> */
    private array $roleTokens = [];

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '';
        $this->dbName = 'bs_role_fix_' . bin2hex(random_bytes(4));

        try {
            $this->adminPdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $this->adminPdo->exec("CREATE DATABASE `{$this->dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            if (getenv('DB_HOST') !== false) {
                $this->fail('Database configured via DB_HOST but unreachable: ' . $e->getMessage());
            }
            $this->markTestSkipped('No local MySQL/MariaDB available.');
        }

        $this->connection = new Connection([
            'host' => $host,
            'port' => $port,
            'database' => $this->dbName,
            'username' => $user,
            'password' => $pass,
            'prefix' => 'bs_',
        ]);

        $migrator = new Migrator($this->connection);
        $migrator->registerNamespace('core', dirname(__DIR__, 3) . '/migrations');
        $migrator->migrate();

        $this->tempDir = sys_get_temp_dir() . '/bs_role_matrix_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir . '/logs', 0777, true);
        mkdir($this->tempDir . '/cache', 0777, true);
        mkdir($this->tempDir . '/storage/media', 0777, true);
        mkdir($this->tempDir . '/storage/tmp', 0777, true);

        $collections = new CollectionRegistry($this->connection);
        $fieldTypes = new FieldTypeRegistry();
        $auditLogger = new AuditLogger($this->connection);
        $compiler = new PermissionCompiler($this->connection);
        $authorizer = new Authorizer($this->connection, $compiler);
        $publicCache = new PublicResponseCache($this->tempDir . '/cache/public_http');
        $confirm = new ConfirmTokenService('01234567890123456789012345678901', $this->connection);

        $planner = new SchemaPlanner($this->connection, $collections, $fieldTypes);
        $lock = new SchemaLock($this->connection);
        $applier = new SchemaApplier($this->connection, $collections, $lock);

        $slugService = new SlugService($this->connection);
        $hierarchy = new HierarchyValidator($this->connection);
        $versions = new VersionManager($this->connection);
        $relations = new RelationManager($this->connection);

        $entries = new EntryRepository(
            $this->connection,
            $collections,
            $fieldTypes,
            $slugService,
            $hierarchy,
            $versions,
            $relations
        );

        $this->taxonomies = new TaxonomyRepository($this->connection);
        $this->terms = new TermRepository($this->connection);
        $this->folders = new MediaFolderRepository($this->connection);
        $processor = new MediaProcessor($this->connection, $this->tempDir . '/storage/media');
        $this->media = new MediaRepository(
            $this->connection,
            $processor,
            $this->tempDir . '/storage/media'
        );

        $chunkManager = new ChunkUploadManager(
            $this->tempDir . '/storage/tmp'
        );

        $colCtrl = new CollectionsController(
            $this->connection,
            $collections,
            $planner,
            $applier,
            $authorizer,
            $confirm,
            $auditLogger
        );
        $queryParser = new QueryParser();
        $entCtrl = new EntriesController(
            $collections,
            $entries,
            $queryParser,
            $versions,
            $authorizer,
            $auditLogger,
            $confirm
        );
        $pubCtrl = new PublicEntriesController(
            $this->connection,
            $collections,
            $entries,
            $queryParser,
            $publicCache
        );
        $taxCtrl = new TaxonomiesController($this->taxonomies, $this->terms, $authorizer, $publicCache);
        $folderCtrl = new MediaFoldersController($this->folders, $authorizer);
        $mediaCtrl = new MediaController(
            $this->media,
            $chunkManager,
            $authorizer,
            $this->tempDir . '/storage/tmp'
        );

        $dummyAuth = new class () extends \BsCms\Http\Controllers\AuthController {
            public function __construct()
            {
            }
        };
        $dummyMe = new class () extends \BsCms\Http\Controllers\MeController {
            public function __construct()
            {
            }
        };
        $dummyUsers = new class () extends \BsCms\Http\Controllers\UsersController {
            public function __construct()
            {
            }
        };
        $dummyRoles = new class () extends \BsCms\Http\Controllers\RolesController {
            public function __construct()
            {
            }
        };
        $dummyTokens = new class () extends \BsCms\Http\Controllers\TokensController {
            public function __construct()
            {
            }
        };
        $dummyAudit = new class () extends \BsCms\Http\Controllers\AuditController {
            public function __construct()
            {
            }
        };
        $dummyEmail = new class () extends \BsCms\Http\Controllers\EmailSettingsController {
            public function __construct()
            {
            }
        };
        $dummyNotif = new class () extends \BsCms\Http\Controllers\NotificationsController {
            public function __construct()
            {
            }
        };

        $routes = new RouteCollection();
        ApiRoutes::register(
            $routes,
            $dummyAuth,
            $dummyMe,
            $dummyUsers,
            $dummyRoles,
            $dummyTokens,
            $dummyAudit,
            $dummyEmail,
            $dummyNotif,
            collections: $colCtrl,
            entries: $entCtrl,
            publicEntries: $pubCtrl,
            taxonomies: $taxCtrl,
            mediaFolders: $folderCtrl,
            media: $mediaCtrl
        );
        $router = new Router($routes);

        $sessionManager = new SessionManager($this->connection, 3600);
        $this->apiTokenService = new ApiTokenService($this->connection, $auditLogger);

        $fileLogger = new \BsCms\Log\FileLogger($this->tempDir . '/logs/app.log');
        $this->kernel = new Kernel($router);
        $this->kernel->addMiddleware(new RequestIdMiddleware());
        $this->kernel->addMiddleware(new ErrorHandlerMiddleware(new Environment(Environment::TESTING), $fileLogger));
        $this->kernel->addMiddleware(new JsonBodyMiddleware());
        $this->kernel->addMiddleware(new SessionMiddleware($sessionManager, $this->connection));
        $this->kernel->addMiddleware(new AuthenticateMiddleware($this->apiTokenService));

        // Create tokens for all 9 system roles
        $rolesMap = [
            'owner' => SystemRoles::ROLE_OWNER,
            'admin' => SystemRoles::ROLE_ADMIN,
            'developer' => SystemRoles::ROLE_DEVELOPER,
            'editor' => SystemRoles::ROLE_EDITOR,
            'author' => SystemRoles::ROLE_AUTHOR,
            'contributor' => SystemRoles::ROLE_CONTRIBUTOR,
            'viewer' => SystemRoles::ROLE_VIEWER,
            'ai_draft' => SystemRoles::ROLE_AI_DRAFT,
            'ai_publisher' => SystemRoles::ROLE_AI_PUBLISHER,
        ];

        foreach ($rolesMap as $name => $id) {
            $this->roleTokens[$name] = $this->createActorToken($id, $name);
        }

        // Seed a default taxonomy 'topics' for term tests
        $this->taxonomies->create([
            'handle' => 'topics',
            'name' => 'Topics',
            'hierarchical' => true,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->adminPdo !== null && $this->dbName !== '') {
            try {
                $this->adminPdo->exec("DROP DATABASE IF EXISTS `{$this->dbName}`");
            } catch (\Throwable) {
            }
        }
        if (isset($this->tempDir) && is_dir($this->tempDir)) {
            $this->deleteRecursive($this->tempDir);
        }
        parent::tearDown();
    }

    private function deleteRecursive(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->deleteRecursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function createActorToken(string $roleId, string $roleName): string
    {
        $userId = Ulid::generate();
        $this->connection->execute(
            "INSERT INTO `{$this->connection->prefixTable('users')}`
             (`id`, `email`, `password_hash`, `status`, `name`, `failed_logins`, `created_at`, `updated_at`)
             VALUES (?, ?, 'hash', 'active', ?, 0, NOW(), NOW())",
            [$userId, "{$roleName}_" . bin2hex(random_bytes(3)) . "@test.local", ucfirst($roleName)]
        );
        $this->connection->execute(
            "INSERT INTO `{$this->connection->prefixTable('user_roles')}` (`user_id`, `role_id`)
             VALUES (?, ?)",
            [$userId, $roleId]
        );

        $tokenData = $this->apiTokenService->create(
            userId: $userId,
            name: "Token for {$roleName}",
            roleId: $roleId,
            scopes: ['*']
        );
        return $tokenData['token'];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, string $uri, ?array $body = null, ?string $token = null): ResponseInterface
    {
        $req = new ServerRequest($method, $uri);
        if ($token !== null) {
            $req = $req->withHeader('Authorization', "Bearer {$token}");
        }
        if ($body !== null) {
            $req = $req->withHeader('Content-Type', 'application/json');
            $req->getBody()->write((string) json_encode($body));
            $req->getBody()->rewind();
        }
        return $this->kernel->handle($req);
    }

    /**
     * Taxonomy Definitions (create/update/delete) must be ADMIN/DEVELOPER/OWNER ONLY.
     * Editors, authors, contributors, viewers, AI MUST receive 403.
     */
    public function testTaxonomyDefinitionsRoleMatrix(): void
    {
        // 1. Allowed roles: owner, admin, developer
        $allowed = ['owner', 'admin', 'developer'];
        foreach ($allowed as $role) {
            $handle = 'tax_' . $role;
            $res = $this->request('POST', '/api/v1/taxonomies', [
                'handle' => $handle,
                'name' => ucfirst($handle),
            ], $this->roleTokens[$role]);

            $this->assertSame(201, $res->getStatusCode(), "Role '{$role}' must be allowed to create taxonomy definitions.");
        }

        // 2. Forbidden roles: editor, author, contributor, viewer, ai_draft, ai_publisher
        $forbidden = ['editor', 'author', 'contributor', 'viewer', 'ai_draft', 'ai_publisher'];
        foreach ($forbidden as $role) {
            $handle = 'tax_' . $role;
            $res = $this->request('POST', '/api/v1/taxonomies', [
                'handle' => $handle,
                'name' => ucfirst($handle),
            ], $this->roleTokens[$role]);

            $this->assertSame(403, $res->getStatusCode(), "Role '{$role}' must be forbidden (403) from creating taxonomy definitions.");
        }
    }

    /**
     * Term Management:
     * Editors, Admins, Developers, Owners, and AI Publishers CAN create terms.
     * Authors, Contributors, Viewers, and AI Drafts CANNOT create terms (403).
     */
    public function testTermsRoleMatrix(): void
    {
        // Allowed roles to create terms
        $allowed = ['owner', 'admin', 'developer', 'editor', 'ai_publisher'];
        foreach ($allowed as $role) {
            $slug = 'term-' . str_replace('_', '-', $role);
            $res = $this->request('POST', '/api/v1/taxonomies/topics/terms', [
                'name' => 'Term for ' . $role,
                'slug' => $slug,
            ], $this->roleTokens[$role]);

            $this->assertSame(201, $res->getStatusCode(), "Role '{$role}' must be allowed to create terms: " . (string) $res->getBody());
        }

        // Forbidden roles
        $forbidden = ['author', 'contributor', 'viewer', 'ai_draft'];
        foreach ($forbidden as $role) {
            $slug = 'term-bad-' . str_replace('_', '-', $role);
            $res = $this->request('POST', '/api/v1/taxonomies/topics/terms', [
                'name' => 'Bad Term ' . $role,
                'slug' => $slug,
            ], $this->roleTokens[$role]);

            $this->assertSame(403, $res->getStatusCode(), "Role '{$role}' must be forbidden (403) from creating terms.");
        }
    }

    /**
     * Media Folders:
     * Owners, Admins, Developers, and Editors can create media folders.
     * Viewers cannot (403).
     */
    public function testMediaFoldersRoleMatrix(): void
    {
        $allowed = ['owner', 'admin', 'developer', 'editor'];
        foreach ($allowed as $role) {
            $res = $this->request('POST', '/api/v1/media/folders', [
                'name' => 'Folder ' . $role,
            ], $this->roleTokens[$role]);

            $this->assertSame(201, $res->getStatusCode(), "Role '{$role}' must be allowed to create media folders.");
        }

        $resViewer = $this->request('POST', '/api/v1/media/folders', [
            'name' => 'Viewer Folder',
        ], $this->roleTokens['viewer']);

        $this->assertSame(403, $resViewer->getStatusCode(), "Viewer must be forbidden from creating media folders.");
    }

    /**
     * Media Access:
     * Viewers can read media list (200).
     * Viewers cannot delete media (403).
     */
    public function testMediaReadAndManagementMatrix(): void
    {
        // List media
        $resList = $this->request('GET', '/api/v1/media', null, $this->roleTokens['viewer']);
        $this->assertSame(200, $resList->getStatusCode(), "Viewer must be allowed to list media.");

        // Insert media item to test unauthorized deletion
        $mediaId = Ulid::generate();
        $this->connection->execute(
            "INSERT INTO `{$this->connection->prefixTable('media')}` (
                `id`, `filename`, `original_name`, `mime`, `extension`, `size_bytes`,
                `storage_path`, `content_hash`, `created_at`, `updated_at`
            ) VALUES (?, 'test.jpg', 'test.jpg', 'image/jpeg', 'jpg', 100, 'test.jpg', 'hash', NOW(), NOW())",
            [$mediaId]
        );

        // Delete media forbidden for viewer
        $resDel = $this->request('DELETE', "/api/v1/media/{$mediaId}", null, $this->roleTokens['viewer']);
        $this->assertSame(403, $resDel->getStatusCode(), "Viewer must be forbidden from deleting media.");
    }
}
