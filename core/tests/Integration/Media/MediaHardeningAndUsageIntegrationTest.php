<?php

declare(strict_types=1);

namespace BsCms\Tests\Integration\Media;

use BsCms\Audit\AuditLogger;
use BsCms\Auth\Actor;
use BsCms\Auth\ApiTokenService;
use BsCms\Auth\SessionManager;
use BsCms\Auth\SystemRoles;
use BsCms\Authz\Authorizer;
use BsCms\Authz\PermissionCompiler;
use BsCms\Content\EntryRepository;
use BsCms\Content\HierarchyValidator;
use BsCms\Content\RelationManager;
use BsCms\Content\SlugService;
use BsCms\Content\VersionManager;
use BsCms\Database\Connection;
use BsCms\Database\Migrator;
use BsCms\Http\Controllers\MediaController;
use BsCms\Http\Controllers\MediaFoldersController;
use BsCms\Http\Kernel;
use BsCms\Http\Middleware\AuthenticateMiddleware;
use BsCms\Http\Middleware\ErrorHandlerMiddleware;
use BsCms\Http\Middleware\JsonBodyMiddleware;
use BsCms\Http\Middleware\RequestIdMiddleware;
use BsCms\Http\Middleware\SessionMiddleware;
use BsCms\Kernel\Environment;
use BsCms\Media\ChunkUploadManager;
use BsCms\Media\MediaFolderRepository;
use BsCms\Media\MediaProcessor;
use BsCms\Media\MediaRepository;
use BsCms\Routing\ApiRoutes;
use BsCms\Routing\RouteCollection;
use BsCms\Routing\Router;
use BsCms\Schema\CollectionRegistry;
use BsCms\Schema\FieldTypeRegistry;
use BsCms\Schema\SchemaApplier;
use BsCms\Schema\SchemaLock;
use BsCms\Schema\SchemaPlanner;
use BsCms\Security\Ulid;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\UploadedFile;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class MediaHardeningAndUsageIntegrationTest extends TestCase
{
    private Connection $connection;
    private string $dbName;
    private string $tempDir;
    private string $mediaDir;
    private ?PDO $adminPdo = null;

    private MediaRepository $mediaRepository;
    private MediaFolderRepository $folderRepository;
    private ChunkUploadManager $chunkManager;
    private EntryRepository $entryRepository;
    private SchemaPlanner $planner;
    private SchemaApplier $applier;
    private Kernel $kernel;
    private string $ownerToken;

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : 'root';
        $this->dbName = 'bs_media_hard_' . bin2hex(random_bytes(4));

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

        $this->tempDir = sys_get_temp_dir() . '/bs_media_hard_' . bin2hex(random_bytes(6));
        $this->mediaDir = $this->tempDir . '/public/media';
        @mkdir($this->mediaDir, 0755, true);
        @mkdir($this->tempDir . '/tmp', 0755, true);
        @mkdir($this->tempDir . '/logs', 0755, true);

        $migrator = new Migrator($this->connection);
        $migrator->registerNamespace('core', dirname(__DIR__, 3) . '/migrations');
        $migrator->migrate();

        $compiler = new PermissionCompiler($this->connection);
        $authorizer = new Authorizer($this->connection, $compiler);

        $processor = new MediaProcessor($this->connection, $this->mediaDir);
        $this->folderRepository = new MediaFolderRepository($this->connection);
        $this->mediaRepository = new MediaRepository($this->connection, $processor, $this->mediaDir);
        $this->chunkManager = new ChunkUploadManager($this->tempDir . '/tmp');

        $colRegistry = new CollectionRegistry($this->connection);
        $fieldRegistry = new FieldTypeRegistry();
        $this->planner = new SchemaPlanner($this->connection, $colRegistry, $fieldRegistry);
        $schemaLock = new SchemaLock($this->connection);
        $this->applier = new SchemaApplier($this->connection, $colRegistry, $schemaLock);

        $slugService = new SlugService($this->connection);
        $hierarchy = new HierarchyValidator($this->connection);
        $auditLogger = new AuditLogger($this->connection);
        $versions = new VersionManager($this->connection);
        $relations = new RelationManager($this->connection);

        $this->entryRepository = new EntryRepository(
            $this->connection,
            $colRegistry,
            $fieldRegistry,
            $slugService,
            $hierarchy,
            $versions,
            $relations
        );

        $mediaCtrl = new MediaController($this->mediaRepository, $this->chunkManager, $authorizer, $this->tempDir . '/tmp');
        $folderCtrl = new MediaFoldersController($this->folderRepository, $authorizer);

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
            mediaFolders: $folderCtrl,
            media: $mediaCtrl
        );
        $router = new Router($routes);

        $sessionManager = new SessionManager($this->connection, 3600);
        $apiTokenService = new ApiTokenService($this->connection, $auditLogger);

        $fileLogger = new \BsCms\Log\FileLogger($this->tempDir . '/logs/app.log');
        $this->kernel = new Kernel($router);
        $this->kernel->addMiddleware(new RequestIdMiddleware());
        $this->kernel->addMiddleware(new ErrorHandlerMiddleware(new Environment(Environment::TESTING), $fileLogger));
        $this->kernel->addMiddleware(new JsonBodyMiddleware());
        $this->kernel->addMiddleware(new SessionMiddleware($sessionManager, $this->connection));
        $this->kernel->addMiddleware(new AuthenticateMiddleware($apiTokenService));

        // Create owner token
        $userId = Ulid::generate();
        $this->connection->execute(
            "INSERT INTO `{$this->connection->prefixTable('users')}`
             (`id`, `email`, `password_hash`, `status`, `name`, `failed_logins`, `created_at`, `updated_at`)
             VALUES (?, 'owner@bscms.test', 'hash', 'active', 'Owner', 0, NOW(), NOW())",
            [$userId]
        );
        $this->connection->execute(
            "INSERT INTO `{$this->connection->prefixTable('user_roles')}` (`user_id`, `role_id`)
             VALUES (?, ?)",
            [$userId, SystemRoles::ROLE_OWNER]
        );
        $tokenData = $apiTokenService->create(
            userId: $userId,
            name: 'Owner Token',
            roleId: SystemRoles::ROLE_OWNER,
            scopes: ['*']
        );
        $this->ownerToken = $tokenData['token'];
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

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, mixed>|null $uploadedFiles
     */
    private function request(
        string $method,
        string $uri,
        ?array $body = null,
        ?array $uploadedFiles = null,
        ?string $token = null
    ): ResponseInterface {
        $req = new ServerRequest($method, $uri);
        if ($token !== null) {
            $req = $req->withHeader('Authorization', "Bearer {$token}");
        }
        if ($body !== null) {
            $req = $req->withHeader('Content-Type', 'application/json');
            $req->getBody()->write((string) json_encode($body));
            $req->getBody()->rewind();
        }
        if ($uploadedFiles !== null) {
            $req = $req->withUploadedFiles($uploadedFiles);
        }
        return $this->kernel->handle($req);
    }

    /**
     * Create a real valid 10x10 PNG file in temporary path.
     */
    private function createSamplePng(): string
    {
        $path = $this->tempDir . '/tmp/' . uniqid('sample_', true) . '.png';
        $im = imagecreatetruecolor(10, 10);
        $blue = (int) imagecolorallocate($im, 0, 0, 255);
        imagefilledrectangle($im, 0, 0, 9, 9, $blue);
        imagepng($im, $path);
        return $path;
    }

    /**
     * 4(a) Nonexistent media ID in media field, richtext image node, and relation field must return 422.
     */
    public function testNonexistentMediaAndRelationValidation(): void
    {
        // 1. Create collection with media, richtext, and relation fields
        $authorsDef = [
            'handle' => 'authors',
            'kind' => 'collection',
            'title_field' => 'title',
            'label_singular' => 'Author',
            'label_plural' => 'Authors',
            'fields' => [],
        ];
        $this->applier->apply($this->planner->plan($authorsDef), $authorsDef);

        $postsDef = [
            'handle' => 'posts',
            'kind' => 'collection',
            'title_field' => 'title',
            'label_singular' => 'Post',
            'label_plural' => 'Posts',
            'fields' => [
                'featured_image' => [
                    'handle' => 'featured_image',
                    'type' => 'media',
                ],
                'body' => [
                    'handle' => 'body',
                    'type' => 'richtext',
                ],
                'author' => [
                    'handle' => 'author',
                    'type' => 'relation',
                    'config' => ['target_collection' => 'authors'],
                ],
            ],
        ];
        $this->applier->apply($this->planner->plan($postsDef), $postsDef);

        // A. Media field rejects nonexistent media ID
        $nonexistentMediaId = Ulid::generate();
        try {
            $this->entryRepository->create('posts', [
                'title' => 'Post With Fake Media',
                'featured_image' => $nonexistentMediaId,
            ]);
            $this->fail('Expected ValidationException for nonexistent media ID in media field.');
        } catch (\BsCms\Operations\Exceptions\ValidationException $e) {
            $this->assertNotEmpty($e->errors);
            $flat = json_encode($e->errors);
            $this->assertStringContainsString('does not exist', (string) $flat);
        }

        // B. Richtext field rejects embedded image node with nonexistent media ID
        try {
            $this->entryRepository->create('posts', [
                'title' => 'Post With Fake Richtext Image',
                'body' => [
                    'type' => 'doc',
                    'content' => [
                        [
                            'type' => 'image',
                            'attrs' => ['id' => $nonexistentMediaId, 'src' => 'fake.jpg'],
                        ],
                    ],
                ],
            ]);
            $this->fail('Expected ValidationException for nonexistent media ID in richtext node.');
        } catch (\BsCms\Operations\Exceptions\ValidationException $e) {
            $this->assertNotEmpty($e->errors);
            $flat = json_encode($e->errors);
            $this->assertStringContainsString('nonexistent media', (string) $flat);
        }

        // C. Relation field rejects nonexistent target entry ID
        $nonexistentAuthorId = Ulid::generate();
        try {
            $this->entryRepository->create('posts', [
                'title' => 'Post With Fake Author Relation',
                'author' => [$nonexistentAuthorId],
            ]);
            $this->fail('Expected ValidationException for nonexistent target in relation field.');
        } catch (\BsCms\Operations\Exceptions\ValidationException $e) {
            $this->assertNotEmpty($e->errors);
            $flat = json_encode($e->errors);
            $this->assertStringContainsString('Referenced relation entry', (string) $flat);
        }
    }

    /**
     * 4(b) GET /media/{id}/usage returns 200 with usage details (previously 500 Unknown column 'c.name').
     */
    public function testGetMediaUsageReturns200WithCollectionName(): void
    {
        // Upload a sample image
        $pngPath = $this->createSamplePng();
        $uploaded = new UploadedFile($pngPath, (int) filesize($pngPath), UPLOAD_ERR_OK, 'sample.png', 'image/png');

        $res = $this->request('POST', '/api/v1/media', null, ['file' => $uploaded], $this->ownerToken);
        $this->assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        $mediaData = json_decode((string) $res->getBody(), true);
        $mediaId = (string) $mediaData['id'];

        // Call GET /api/v1/media/{id}/usage
        $resUsage = $this->request('GET', "/api/v1/media/{$mediaId}/usage", null, null, $this->ownerToken);
        $this->assertSame(200, $resUsage->getStatusCode(), (string) $resUsage->getBody());

        $usageBody = json_decode((string) $resUsage->getBody(), true);
        $this->assertIsArray($usageBody);
        $this->assertArrayHasKey('usages', $usageBody);
    }

    /**
     * 4(c) Hostile upload: "JPEG header + <?php echo 'PWNED'; ?> + FFD9" must be rejected with 422 and store nothing.
     */
    public function testHostileUploadRejectedWith422AndStoresNothing(): void
    {
        $hostilePayload = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x01\x00\x48\x00\x48\x00\x00" .
            "<?php echo 'PWNED'; ?>" .
            "\xFF\xD9";

        $tmpFile = $this->tempDir . '/tmp/evil.jpg';
        file_put_contents($tmpFile, $hostilePayload);

        $uploaded = new UploadedFile($tmpFile, strlen($hostilePayload), UPLOAD_ERR_OK, 'evil.jpg', 'image/jpeg');
        $res = $this->request('POST', '/api/v1/media', null, ['file' => $uploaded], $this->ownerToken);

        $this->assertSame(422, $res->getStatusCode(), "Hostile upload with PHP tags must return 422.");

        // Assert nothing is stored in media directory with the evil payload
        $files = scandir($this->mediaDir) ?: [];
        foreach ($files as $f) {
            if ($f === '.' || $f === '..' || $f === '.htaccess') {
                continue;
            }
            $content = file_get_contents($this->mediaDir . '/' . $f);
            $this->assertStringNotContainsString('PWNED', (string) $content);
            $this->assertStringNotContainsString('<?php', (string) $content);
        }
    }

    /**
     * 4(f) Dedupe: Uploading identical content returns duplicate_of pointing to existing media.
     */
    public function testContentDeduplicationReturnsDuplicateOf(): void
    {
        $pngPath = $this->createSamplePng();
        $file1 = new UploadedFile($pngPath, (int) filesize($pngPath), UPLOAD_ERR_OK, 'orig.png', 'image/png');

        // First upload
        $res1 = $this->request('POST', '/api/v1/media', null, ['file' => $file1], $this->ownerToken);
        $this->assertSame(201, $res1->getStatusCode());
        $body1 = json_decode((string) $res1->getBody(), true);
        $firstId = (string) $body1['id'];

        // Second upload with identical bytes
        $file2 = new UploadedFile($pngPath, (int) filesize($pngPath), UPLOAD_ERR_OK, 'orig_copy.png', 'image/png');
        $res2 = $this->request('POST', '/api/v1/media', null, ['file' => $file2], $this->ownerToken);
        $this->assertContains($res2->getStatusCode(), [200, 201], (string) $res2->getBody());
        $body2 = json_decode((string) $res2->getBody(), true);

        $this->assertSame($firstId, $body2['id']);
        $this->assertSame($firstId, $body2['duplicate_of']);
    }

    /**
     * 8. Media Replace: POST /media/{id}/replace replaces file while keeping ID and references.
     */
    public function testMediaReplaceEndpoint(): void
    {
        // 1. Upload initial 10x10 image
        $pngPath1 = $this->createSamplePng();
        $file1 = new UploadedFile($pngPath1, (int) filesize($pngPath1), UPLOAD_ERR_OK, 'first.png', 'image/png');
        $res1 = $this->request('POST', '/api/v1/media', null, ['file' => $file1], $this->ownerToken);
        $this->assertSame(201, $res1->getStatusCode());
        $media1 = json_decode((string) $res1->getBody(), true);
        $mediaId = (string) $media1['id'];

        // 2. Create larger 20x20 replacement image
        $path2 = $this->tempDir . '/tmp/second.png';
        $im = imagecreatetruecolor(20, 20);
        $red = (int) imagecolorallocate($im, 255, 0, 0);
        imagefilledrectangle($im, 0, 0, 19, 19, $red);
        imagepng($im, $path2);

        $file2 = new UploadedFile($path2, (int) filesize($path2), UPLOAD_ERR_OK, 'second.png', 'image/png');

        // 3. Call replace endpoint
        $resReplace = $this->request('POST', "/api/v1/media/{$mediaId}/replace", null, ['file' => $file2], $this->ownerToken);
        $this->assertSame(200, $resReplace->getStatusCode(), (string) $resReplace->getBody());

        $bodyReplace = json_decode((string) $resReplace->getBody(), true);
        $this->assertSame($mediaId, $bodyReplace['id'], "Media ID must remain identical on replace.");
        $this->assertSame(20, (int) $bodyReplace['width'], "Width must be updated to 20.");
        $this->assertSame(20, (int) $bodyReplace['height'], "Height must be updated to 20.");
    }

    /**
     * 8. Media Bulk Delete: POST /media/bulk deletes multiple media items.
     */
    public function testMediaBulkDelete(): void
    {
        $png1 = $this->createSamplePng();
        $png2 = $this->createSamplePng();

        $f1 = new UploadedFile($png1, (int) filesize($png1), UPLOAD_ERR_OK, 'b1.png', 'image/png');
        $f2 = new UploadedFile($png2, (int) filesize($png2), UPLOAD_ERR_OK, 'b2.png', 'image/png');

        $res1 = $this->request('POST', '/api/v1/media', null, ['file' => $f1], $this->ownerToken);
        $res2 = $this->request('POST', '/api/v1/media', null, ['file' => $f2], $this->ownerToken);

        $m1 = json_decode((string) $res1->getBody(), true);
        $m2 = json_decode((string) $res2->getBody(), true);

        $resBulk = $this->request('POST', '/api/v1/media/bulk', [
            'action' => 'delete',
            'ids' => [$m1['id'], $m2['id']],
        ], null, $this->ownerToken);

        $this->assertSame(200, $resBulk->getStatusCode(), (string) $resBulk->getBody());
        $body = json_decode((string) $resBulk->getBody(), true);
        $this->assertSame(2, $body['affected']);

        // Assert items deleted
        $this->assertNull($this->mediaRepository->findById($m1['id']));
        $this->assertNull($this->mediaRepository->findById($m2['id']));
    }

    /**
     * 8. Folder Move: POST /media/folders/{id}/move moves folder to new parent.
     */
    public function testMediaFolderMove(): void
    {
        $folderA = $this->folderRepository->create('Folder A', null);
        $folderB = $this->folderRepository->create('Folder B', null);

        $resMove = $this->request('POST', "/api/v1/media/folders/{$folderB['id']}/move", [
            'parent_id' => $folderA['id'],
        ], null, $this->ownerToken);

        $this->assertSame(200, $resMove->getStatusCode(), (string) $resMove->getBody());
        $body = json_decode((string) $resMove->getBody(), true);

        $this->assertSame($folderA['id'], $body['parent_id']);
        $this->assertStringStartsWith($folderA['path'], $body['path']);
    }
}
