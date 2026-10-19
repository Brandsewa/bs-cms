<?php

declare(strict_types=1);

namespace BsCms\Tests\Integration\Authz;

use BsCms\Auth\Actor;
use BsCms\Authz\Authorizer;
use BsCms\Authz\PermissionCompiler;
use BsCms\Authz\Subject;
use BsCms\Database\Connection;
use BsCms\Database\Migrator;
use BsCms\Migrations\SeedSystemRoles;
use BsCms\Security\Ulid;
use BsCms\Support\Clock;
use PDO;
use PHPUnit\Framework\TestCase;

final class AuthorizerMatrixTest extends TestCase
{
    private Connection $connection;
    private string $dbName = '';
    private PDO $adminPdo;
    private Authorizer $authorizer;

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '';
        $this->dbName = 'bs_authz_' . bin2hex(random_bytes(4));

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

        $compiler = new PermissionCompiler($this->connection);
        $this->authorizer = new Authorizer($this->connection, $compiler);
    }

    protected function tearDown(): void
    {
        if (isset($this->adminPdo) && $this->dbName !== '') {
            $this->adminPdo->exec("DROP DATABASE IF EXISTS `{$this->dbName}`");
        }
        parent::tearDown();
    }

    public function testOwnerRoleAllowedEverything(): void
    {
        $owner = Actor::user(Ulid::generate(), 'Owner', [SeedSystemRoles::ROLE_OWNER]);

        $this->assertTrue($this->authorizer->can($owner, 'create', Subject::collection('posts'))->isAllowed());
        $this->assertTrue($this->authorizer->can($owner, 'publish', Subject::collection('posts'))->isAllowed());
        $this->assertTrue($this->authorizer->can($owner, 'delete', Subject::schema())->isAllowed());
        $this->assertTrue($this->authorizer->can($owner, 'update', Subject::settings('security_keys'))->isAllowed());
        $this->assertTrue($this->authorizer->can($owner, 'delete', Subject::roles('owner'))->isAllowed());
    }

    public function testAdminRoleDenyBeatsAllow(): void
    {
        $admin = Actor::user(Ulid::generate(), 'Admin', [SeedSystemRoles::ROLE_ADMIN]);

        // Allowed content and settings generally
        $this->assertTrue($this->authorizer->can($admin, 'publish', Subject::collection('posts'))->isAllowed());
        $this->assertTrue($this->authorizer->can($admin, 'update', Subject::settings('general'))->isAllowed());

        // Explicitly denied security_keys update
        $secDecision = $this->authorizer->can($admin, 'update', Subject::settings('security_keys'));
        $this->assertFalse($secDecision->isAllowed(), 'Admin must be explicitly denied updating security keys');

        // Explicitly denied managing owner role
        $roleDecision = $this->authorizer->can($admin, 'delete', Subject::roles('owner'));
        $this->assertFalse($roleDecision->isAllowed(), 'Admin must be explicitly denied deleting owner role');
    }

    public function testEditorRoleContentCapabilities(): void
    {
        $editor = Actor::user(Ulid::generate(), 'Editor', [SeedSystemRoles::ROLE_EDITOR]);

        $this->assertTrue($this->authorizer->can($editor, 'create', Subject::collection('posts'))->isAllowed());
        $this->assertTrue($this->authorizer->can($editor, 'publish', Subject::collection('posts'))->isAllowed());
        $this->assertTrue($this->authorizer->can($editor, 'unpublish', Subject::collection('posts'))->isAllowed());
        $this->assertTrue($this->authorizer->can($editor, 'schedule', Subject::collection('posts'))->isAllowed());
        $this->assertTrue($this->authorizer->can($editor, 'delete', Subject::collection('posts'))->isAllowed());
        $this->assertTrue($this->authorizer->can($editor, 'manage', Subject::term('categories'))->isAllowed());
        $this->assertFalse($this->authorizer->can($editor, 'create', Subject::taxonomy('categories'))->isAllowed());

        // Cannot manage users or settings
        $this->assertFalse($this->authorizer->can($editor, 'create', Subject::users())->isAllowed());
        $this->assertFalse($this->authorizer->can($editor, 'update', Subject::settings('general'))->isAllowed());
    }

    public function testAuthorRoleOwnerCondition(): void
    {
        $authorId = Ulid::generate();
        $author = Actor::user($authorId, 'Author', [SeedSystemRoles::ROLE_AUTHOR]);

        // Can create
        $this->assertTrue($this->authorizer->can($author, 'create', Subject::collection('posts'))->isAllowed());

        // Can update own post
        $ownPost = ['created_by' => $authorId, 'status' => 'draft'];
        $this->assertTrue($this->authorizer->can($author, 'update', Subject::collection('posts'), $ownPost)->isAllowed());

        // Cannot update someone else's post
        $otherPost = ['created_by' => 'someone_else', 'status' => 'draft'];
        $this->assertFalse($this->authorizer->can($author, 'update', Subject::collection('posts'), $otherPost)->isAllowed());

        // Cannot publish even own post
        $this->assertFalse($this->authorizer->can($author, 'publish', Subject::collection('posts'), $ownPost)->isAllowed());
    }

    public function testContributorRoleDraftOnlyCondition(): void
    {
        $contributorId = Ulid::generate();
        $contributor = Actor::user($contributorId, 'Contributor', [SeedSystemRoles::ROLE_CONTRIBUTOR]);

        // Creating draft is allowed
        $draftPost = ['created_by' => $contributorId, 'status' => 'draft'];
        $this->assertTrue($this->authorizer->can($contributor, 'create', Subject::collection('posts'), $draftPost)->isAllowed());

        // Creating published directly is denied
        $publishedPost = ['created_by' => $contributorId, 'status' => 'published'];
        $this->assertFalse($this->authorizer->can($contributor, 'create', Subject::collection('posts'), $publishedPost)->isAllowed());

        // Cannot publish
        $this->assertFalse($this->authorizer->can($contributor, 'publish', Subject::collection('posts'), $draftPost)->isAllowed());
    }

    public function testViewerRoleReadOnly(): void
    {
        $viewer = Actor::user(Ulid::generate(), 'Viewer', [SeedSystemRoles::ROLE_VIEWER]);

        $this->assertTrue($this->authorizer->can($viewer, 'read', Subject::collection('posts'))->isAllowed());
        $this->assertFalse($this->authorizer->can($viewer, 'create', Subject::collection('posts'))->isAllowed());
        $this->assertFalse($this->authorizer->can($viewer, 'delete', Subject::collection('posts'))->isAllowed());
    }

    public function testAiDraftModeEnforcement(): void
    {
        // Even with an admin-like role, aiMode = draft_only stops publishing/deletion
        $aiActor = Actor::apiToken('tok_1', 'AI Bot', 'usr_1', [SeedSystemRoles::ROLE_ADMIN], Actor::AI_MODE_DRAFT_ONLY);

        $this->assertFalse($this->authorizer->can($aiActor, 'publish', Subject::collection('posts'))->isAllowed());
        $this->assertFalse($this->authorizer->can($aiActor, 'delete', Subject::collection('posts'))->isAllowed());
        $this->assertFalse($this->authorizer->can($aiActor, 'update', Subject::settings('general'))->isAllowed());
    }

    public function testLastOwnerProtection(): void
    {
        $usersTable = $this->connection->prefixTable('users');
        $userRolesTable = $this->connection->prefixTable('user_roles');
        $now = Clock::sql(Clock::now());

        $ownerId = Ulid::generate();
        $this->connection->getPdo()->exec("INSERT INTO `{$usersTable}` (`id`, `email`, `name`, `status`, `created_at`, `updated_at`)
            VALUES ('{$ownerId}', 'owner@example.com', 'Owner One', 'active', '{$now}', '{$now}')");
        $this->connection->getPdo()->exec("INSERT INTO `{$userRolesTable}` (`user_id`, `role_id`)
            VALUES ('{$ownerId}', '" . SeedSystemRoles::ROLE_OWNER . "')");

        // Exactly 1 active owner exists: deleting or downgrading must be denied!
        $this->assertFalse($this->authorizer->canModifyOwner($ownerId, 'delete')->isAllowed());
        $this->assertFalse($this->authorizer->canModifyOwner($ownerId, 'suspend')->isAllowed());
        $this->assertFalse($this->authorizer->canModifyOwner($ownerId, 'downgrade')->isAllowed());

        // Add a second active owner
        $owner2Id = Ulid::generate();
        $this->connection->getPdo()->exec("INSERT INTO `{$usersTable}` (`id`, `email`, `name`, `status`, `created_at`, `updated_at`)
            VALUES ('{$owner2Id}', 'owner2@example.com', 'Owner Two', 'active', '{$now}', '{$now}')");
        $this->connection->getPdo()->exec("INSERT INTO `{$userRolesTable}` (`user_id`, `role_id`)
            VALUES ('{$owner2Id}', '" . SeedSystemRoles::ROLE_OWNER . "')");

        // Now 2 owners exist: modifying one owner is allowed
        $this->assertTrue($this->authorizer->canModifyOwner($ownerId, 'delete')->isAllowed());
    }
}
