<?php

declare(strict_types=1);

namespace BsCms\Tests\Integration\Media;

use BsCms\Audit\AuditLogger;
use BsCms\Auth\Actor;
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
use BsCms\Media\ChunkUploadManager;
use BsCms\Media\MediaFolderRepository;
use BsCms\Media\MediaProcessor;
use BsCms\Media\MediaRepository;
use BsCms\Schema\CollectionRegistry;
use BsCms\Schema\FieldTypeRegistry;
use BsCms\Schema\SchemaApplier;
use BsCms\Schema\SchemaLock;
use BsCms\Schema\SchemaPlanner;
use BsCms\Security\Ulid;
use BsCms\Users\RoleRepository;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\UploadedFile;
use PDO;
use PHPUnit\Framework\TestCase;

final class MediaIntegrationTest extends TestCase
{
    private Connection $connection;
    private string $dbName;
    private string $prefix;
    private string $tempDir;
    private string $mediaDir;
    private PDO $adminPdo;

    private MediaRepository $mediaRepository;
    private MediaFolderRepository $folderRepository;
    private ChunkUploadManager $chunkManager;
    private MediaController $mediaController;
    private MediaFoldersController $foldersController;
    private EntryRepository $entryRepository;
    private Actor $ownerActor;

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : 'root';

        try {
            $this->adminPdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (\Throwable $e) {
            if (getenv('DB_HOST') !== false) {
                $this->fail('Database configured via DB_HOST but unreachable: ' . $e->getMessage());
            }
            $this->markTestSkipped('No local MySQL/MariaDB available.');
        }

        $this->dbName = 'bs_media_' . bin2hex(random_bytes(4));
        $this->prefix = 'bs_';
        $this->adminPdo->exec("CREATE DATABASE `{$this->dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $this->connection = new Connection([
            'host' => $host,
            'port' => $port,
            'database' => $this->dbName,
            'username' => $user,
            'password' => $pass,
            'prefix' => $this->prefix,
        ]);

        $this->tempDir = sys_get_temp_dir() . '/bs_media_int_' . bin2hex(random_bytes(6));
        $this->mediaDir = $this->tempDir . '/public/media';
        @mkdir($this->mediaDir, 0755, true);
        @mkdir($this->tempDir . '/tmp', 0755, true);

        // Run migrations
        $migrator = new Migrator($this->connection);
        $migrator->registerNamespace('core', dirname(__DIR__, 3) . '/migrations');
        $migrator->migrate();

        $compiler = new PermissionCompiler($this->connection);
        $authorizer = new Authorizer($this->connection, $compiler);

        $processor = new MediaProcessor($this->connection, $this->mediaDir);
        $this->mediaRepository = new MediaRepository($this->connection, $processor, $this->mediaDir);
        $this->folderRepository = new MediaFolderRepository($this->connection);
        $this->chunkManager = new ChunkUploadManager($this->tempDir . '/tmp');

        $this->mediaController = new MediaController($this->mediaRepository, $this->chunkManager, $authorizer, $this->tempDir . '/tmp');
        $this->foldersController = new MediaFoldersController($this->folderRepository, $authorizer);

        $collectionRegistry = new CollectionRegistry($this->connection);
        $fieldTypeRegistry = new FieldTypeRegistry();
        $slugService = new SlugService($this->connection);
        $hierarchy = new HierarchyValidator($this->connection);
        $auditLogger = new AuditLogger($this->connection);
        $versions = new VersionManager($this->connection);
        $relations = new RelationManager($this->connection);

        $this->entryRepository = new EntryRepository(
            $this->connection,
            $collectionRegistry,
            $fieldTypeRegistry,
            $slugService,
            $hierarchy,
            $versions,
            $relations
        );

        $ownerId = Ulid::generate();
        $this->connection->execute(
            "INSERT INTO `{$this->prefix}users` (`id`, `name`, `email`, `password_hash`, `status`, `created_at`, `updated_at`)
             VALUES (?, 'Owner User', 'owner@example.com', 'hash', 'active', NOW(), NOW())",
            [$ownerId]
        );
        $this->connection->execute(
            "INSERT INTO `{$this->prefix}user_roles` (`user_id`, `role_id`) VALUES (?, ?)",
            [$ownerId, SystemRoles::ROLE_OWNER]
        );
        $this->ownerActor = Actor::user($ownerId, 'Owner', [SystemRoles::ROLE_OWNER]);

        // Set up test collection with media and richtext fields
        $planner = new SchemaPlanner($this->connection, $collectionRegistry, $fieldTypeRegistry);
        $lock = new SchemaLock($this->connection);
        $applier = new SchemaApplier($this->connection, $collectionRegistry, $lock);

        $articlesSchema = [
            'handle' => 'articles',
            'name' => 'Articles',
            'label_singular' => 'Article',
            'label_plural' => 'Articles',
            'kind' => 'collection',
            'title_field' => 'title',
            'fields' => [
                'featured_image' => ['type' => 'media', 'label' => 'Featured Image', 'config' => ['multiple' => false]],
                'body' => ['type' => 'richtext', 'label' => 'Body'],
            ],
        ];

        $plan = $planner->plan($articlesSchema);
        $applier->apply($plan, $articlesSchema);
    }

    protected function tearDown(): void
    {
        if (isset($this->adminPdo) && $this->dbName !== '') {
            try {
                $this->adminPdo->exec("DROP DATABASE IF EXISTS `{$this->dbName}`");
            } catch (\Throwable) {
            }
        }

        if (isset($this->tempDir) && $this->tempDir !== '') {
            $this->deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    public function testMediaUploadWithPolyglotStrippingAndSecureNaming(): void
    {
        // 1. Create a JPEG image with PHP code appended to EOF
        $im = imagecreatetruecolor(50, 50);
        $this->assertNotFalse($im);
        $blue = imagecolorallocate($im, 0, 0, 255);
        $this->assertNotFalse($blue);
        imagefill($im, 0, 0, $blue);

        $tmpJpeg = $this->tempDir . '/tmp_upload.jpg';
        imagejpeg($im, $tmpJpeg, 90);

        $jpegData = file_get_contents($tmpJpeg);
        $this->assertIsString($jpegData);
        $evilPayload = '<?php echo "EVIL_POLYGLOT_EXECUTION"; phpinfo(); ?>';
        file_put_contents($tmpJpeg, $jpegData . "\n" . $evilPayload);

        // Upload through MediaController
        $uploadedFile = new UploadedFile(
            $tmpJpeg,
            (int) filesize($tmpJpeg),
            UPLOAD_ERR_OK,
            'evil.jpg.php',
            'image/jpeg'
        );

        $request = (new ServerRequest('POST', '/api/v1/media'))
            ->withAttribute('actor', $this->ownerActor)
            ->withUploadedFiles(['file' => $uploadedFile])
            ->withParsedBody(['title' => 'Sample Blue Image']);

        $response = $this->mediaController->upload($request);
        $this->assertSame(201, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $mediaId = $body['id'];
        $storedFilename = $body['filename'];

        // Rule 1: Filename on disk is strictly <ulid>.<derived_ext>, NEVER evil.jpg.php
        $this->assertSame("{$mediaId}.jpg", $storedFilename);
        $this->assertSame('evil.jpg.php', $body['original_name']);

        // Rule 2: Appended PHP payload is stripped by GD re-encoding
        $storedDiskPath = $this->mediaDir . '/' . $storedFilename;
        $this->assertFileExists($storedDiskPath);
        $storedData = file_get_contents($storedDiskPath);
        $this->assertIsString($storedData);
        $this->assertStringNotContainsString('EVIL_POLYGLOT_EXECUTION', $storedData);
        $this->assertStringNotContainsString('<?php', $storedData);

        // Rule 3: Variants were generated
        $this->assertNotEmpty($body['variants']);
        $this->assertNotNull($body['dominant_color']);

        @unlink($tmpJpeg);
    }

    public function testMaliciousPhpFileUploadIsRejected(): void
    {
        $tmpEvil = $this->tempDir . '/evil.php';
        file_put_contents($tmpEvil, '<?php echo "I am a PHP script"; ?>');

        $uploadedFile = new UploadedFile(
            $tmpEvil,
            (int) filesize($tmpEvil),
            UPLOAD_ERR_OK,
            'evil.php',
            'application/x-php'
        );

        $request = (new ServerRequest('POST', '/api/v1/media'))
            ->withAttribute('actor', $this->ownerActor)
            ->withUploadedFiles(['file' => $uploadedFile]);

        $response = $this->mediaController->upload($request);
        $this->assertSame(422, $response->getStatusCode());

        @unlink($tmpEvil);
    }

    public function testMediaFoldersHierarchyAndCyclePrevention(): void
    {
        // 1. Create root folder
        $createRoot = (new ServerRequest('POST', '/api/v1/media/folders'))
            ->withAttribute('actor', $this->ownerActor)
            ->withParsedBody(['name' => 'Marketing']);
        $respRoot = $this->foldersController->create($createRoot);
        $this->assertSame(201, $respRoot->getStatusCode());
        $rootData = json_decode((string) $respRoot->getBody(), true);
        $rootId = $rootData['id'];
        $this->assertSame('/Marketing/', $rootData['path']);

        // 2. Create subfolder
        $createSub = (new ServerRequest('POST', '/api/v1/media/folders'))
            ->withAttribute('actor', $this->ownerActor)
            ->withParsedBody(['name' => 'Banners', 'parent_id' => $rootId]);
        $respSub = $this->foldersController->create($createSub);
        $this->assertSame(201, $respSub->getStatusCode());
        $subData = json_decode((string) $respSub->getBody(), true);
        $subId = $subData['id'];
        $this->assertSame('/Marketing/Banners/', $subData['path']);

        // 3. Move root folder into subfolder -> rejected (cycle prevention)
        $moveReq = (new ServerRequest('PATCH', "/api/v1/media/folders/{$rootId}"))
            ->withAttribute('actor', $this->ownerActor)
            ->withParsedBody(['parent_id' => $subId]);
        $moveResp = $this->foldersController->update($moveReq, ['id' => $rootId]);
        $this->assertSame(422, $moveResp->getStatusCode());

        // 4. Delete root folder while it contains subfolders -> rejected
        $delReq = (new ServerRequest('DELETE', "/api/v1/media/folders/{$rootId}"))
            ->withAttribute('actor', $this->ownerActor);
        $delResp = $this->foldersController->delete($delReq, ['id' => $rootId]);
        $this->assertSame(409, $delResp->getStatusCode());

        // 5. Delete subfolder then root folder -> succeeds
        $delSubReq = (new ServerRequest('DELETE', "/api/v1/media/folders/{$subId}"))
            ->withAttribute('actor', $this->ownerActor);
        $this->assertSame(200, $this->foldersController->delete($delSubReq, ['id' => $subId])->getStatusCode());
        $this->assertSame(200, $this->foldersController->delete($delReq, ['id' => $rootId])->getStatusCode());
    }

    public function testChunkedUploadLifecycleViaHttp(): void
    {
        $part1 = "First chunk of content. ";
        $part2 = "Second chunk of content.";
        $full = $part1 . $part2;
        $totalSize = strlen($full);

        // 1. Init
        $initReq = (new ServerRequest('POST', '/api/v1/media/chunks/init'))
            ->withAttribute('actor', $this->ownerActor)
            ->withParsedBody([
                'original_name' => 'document.txt',
                'total_chunks' => 2,
                'total_size' => $totalSize,
            ]);
        $initResp = $this->mediaController->initChunk($initReq);
        $this->assertSame(201, $initResp->getStatusCode());
        $initData = json_decode((string) $initResp->getBody(), true);
        $uploadId = $initData['upload_id'];

        // 2. Upload chunk 0
        $c0File = $this->tempDir . '/part0.tmp';
        file_put_contents($c0File, $part1);
        $up0 = new UploadedFile($c0File, strlen($part1), UPLOAD_ERR_OK, 'part0.tmp', 'text/plain');
        $c0Req = (new ServerRequest('POST', "/api/v1/media/chunks/{$uploadId}"))
            ->withAttribute('actor', $this->ownerActor)
            ->withUploadedFiles(['file' => $up0])
            ->withParsedBody(['chunk_index' => 0]);
        $c0Resp = $this->mediaController->uploadChunk($c0Req, ['id' => $uploadId]);
        $this->assertSame(200, $c0Resp->getStatusCode());

        // 3. Status check
        $statusReq = (new ServerRequest('GET', "/api/v1/media/chunks/{$uploadId}"))
            ->withAttribute('actor', $this->ownerActor);
        $statusResp = $this->mediaController->chunkStatus($statusReq, ['id' => $uploadId]);
        $this->assertSame(200, $statusResp->getStatusCode());
        $statusData = json_decode((string) $statusResp->getBody(), true);
        $this->assertSame([0], $statusData['uploaded_chunks']);
        $this->assertFalse($statusData['is_complete']);

        // 4. Upload chunk 1
        $c1File = $this->tempDir . '/part1.tmp';
        file_put_contents($c1File, $part2);
        $up1 = new UploadedFile($c1File, strlen($part2), UPLOAD_ERR_OK, 'part1.tmp', 'text/plain');
        $c1Req = (new ServerRequest('POST', "/api/v1/media/chunks/{$uploadId}"))
            ->withAttribute('actor', $this->ownerActor)
            ->withUploadedFiles(['file' => $up1])
            ->withParsedBody(['chunk_index' => 1]);
        $c1Resp = $this->mediaController->uploadChunk($c1Req, ['id' => $uploadId]);
        $this->assertSame(200, $c1Resp->getStatusCode());

        // 5. Assemble
        $assembleReq = (new ServerRequest('POST', "/api/v1/media/chunks/{$uploadId}/assemble"))
            ->withAttribute('actor', $this->ownerActor)
            ->withParsedBody(['title' => 'Assembled Text Document']);
        $assembleResp = $this->mediaController->assembleChunk($assembleReq, ['id' => $uploadId]);
        $this->assertSame(201, $assembleResp->getStatusCode());
        $mediaData = json_decode((string) $assembleResp->getBody(), true);
        $mediaId = $mediaData['id'];
        $this->assertSame('Assembled Text Document', $mediaData['title']);
        $this->assertSame('txt', $mediaData['extension']);

        // 6. Metadata update via PATCH
        $patchReq = (new ServerRequest('PATCH', "/api/v1/media/{$mediaId}"))
            ->withAttribute('actor', $this->ownerActor)
            ->withParsedBody([
                'alt_text' => 'Updated Alt Text',
                'caption' => 'Updated Caption',
            ]);
        $patchResp = $this->mediaController->update($patchReq, ['id' => $mediaId]);
        $this->assertSame(200, $patchResp->getStatusCode());
        $patched = json_decode((string) $patchResp->getBody(), true);
        $this->assertSame('Updated Alt Text', $patched['alt_text']);
        $this->assertSame('Updated Caption', $patched['caption']);

        // 7. List
        $listReq = (new ServerRequest('GET', '/api/v1/media'))
            ->withAttribute('actor', $this->ownerActor);
        $listResp = $this->mediaController->list($listReq);
        $this->assertSame(200, $listResp->getStatusCode());
        $listData = json_decode((string) $listResp->getBody(), true);
        $this->assertGreaterThanOrEqual(1, $listData['total']);

        @unlink($c0File);
        @unlink($c1File);
    }

    public function testMediaUsageTrackingAndForceDelete(): void
    {
        // 1. Create a clean image and upload
        $im = imagecreatetruecolor(30, 30);
        $this->assertNotFalse($im);
        $tmp = $this->tempDir . '/hero.jpg';
        imagejpeg($im, $tmp);

        $uploaded = new UploadedFile($tmp, (int) filesize($tmp), UPLOAD_ERR_OK, 'hero.jpg', 'image/jpeg');
        $req = (new ServerRequest('POST', '/api/v1/media'))
            ->withAttribute('actor', $this->ownerActor)
            ->withUploadedFiles(['file' => $uploaded]);
        $res = $this->mediaController->upload($req);
        $media = json_decode((string) $res->getBody(), true);
        $mediaId = $media['id'];
        @unlink($tmp);

        // 2. Create article referencing this media item in featured_image
        $article = $this->entryRepository->create(
            'articles',
            [
                'title' => 'Article with Media',
                'featured_image' => $mediaId,
            ],
            'user',
            'Owner',
            $this->ownerActor->id
        );
        $articleId = $article['id'];

        // 3. Verify media_usage table has reference and usage_count is 1
        $usageStmt = $this->connection->execute(
            "SELECT COUNT(*) FROM `{$this->prefix}media_usage` WHERE `media_id` = ? AND `entry_id` = ?",
            [$mediaId, $articleId]
        );
        $this->assertSame(1, (int) $usageStmt->fetchColumn());

        $refreshedMedia = $this->mediaRepository->findById($mediaId);
        $this->assertNotNull($refreshedMedia);
        $this->assertSame(1, (int) $refreshedMedia['usage_count']);

        // 4. Attempt to delete media without force -> 409 Conflict
        $delReq = (new ServerRequest('DELETE', "/api/v1/media/{$mediaId}"))
            ->withAttribute('actor', $this->ownerActor);
        $delResp = $this->mediaController->delete($delReq, ['id' => $mediaId]);
        $this->assertSame(409, $delResp->getStatusCode());

        // 5. Delete with force=true -> 200 OK
        $delForceReq = (new ServerRequest('DELETE', "/api/v1/media/{$mediaId}"))
            ->withAttribute('actor', $this->ownerActor)
            ->withQueryParams(['force' => 'true']);
        $delForceResp = $this->mediaController->delete($delForceReq, ['id' => $mediaId]);
        $this->assertSame(200, $delForceResp->getStatusCode());

        // 6. Assert media is soft deleted and usages cleared
        $deletedMedia = $this->mediaRepository->findById($mediaId, true);
        $this->assertNotNull($deletedMedia);
        $this->assertNotNull($deletedMedia['deleted_at']);

        $usageAfter = $this->connection->execute(
            "SELECT COUNT(*) FROM `{$this->prefix}media_usage` WHERE `media_id` = ?",
            [$mediaId]
        );
        $this->assertSame(0, (int) $usageAfter->fetchColumn());

        // 7. Permanent purge deletes file from disk
        $this->mediaRepository->delete($mediaId, true, true);
        $diskFile = $this->mediaDir . '/' . $media['filename'];
        $this->assertFileDoesNotExist($diskFile);
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = $dir . '/' . $item;
            if (is_dir($p)) {
                $this->deleteDirectory($p);
            } else {
                @unlink($p);
            }
        }

        @rmdir($dir);
    }
}
