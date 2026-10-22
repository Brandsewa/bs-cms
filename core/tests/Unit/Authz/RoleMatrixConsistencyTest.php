<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Authz;

use BsCms\Auth\Actor;
use BsCms\Auth\SystemRoles;
use BsCms\Authz\Authorizer;
use BsCms\Authz\Permission;
use BsCms\Authz\PermissionCompiler;
use BsCms\Authz\Subject;
use BsCms\Database\Connection;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Unit test validating role matrix consistency across system roles:
 * - Developer vs Admin maintenance & backup permissions
 * - Webhooks manage/read across admin and developer
 * - Singletons read across contributor vs viewer
 * - Collection export gating
 */
final class RoleMatrixConsistencyTest extends TestCase
{
    private Authorizer $authorizer;

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = $this->createMock(PDO::class);
        $connection = $this->createMock(Connection::class);
        $connection->method('getPdo')->willReturn($pdo);
        $connection->method('prefixTable')->willReturnCallback(fn (string $tbl) => 'bs_' . $tbl);

        $pdo->method('prepare')->willReturnCallback(function (string $sql) {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            $allRows = [
                // Admin
                ['id' => '1', 'role_id' => SystemRoles::ROLE_ADMIN, 'subject_type' => 'collection', 'subject_key' => '*', 'action' => '*', 'effect' => 'allow', 'fields' => null, 'conditions' => null],
                ['id' => '2', 'role_id' => SystemRoles::ROLE_ADMIN, 'subject_type' => 'site', 'subject_key' => '*', 'action' => '*', 'effect' => 'allow', 'fields' => null, 'conditions' => null],
                ['id' => '3', 'role_id' => SystemRoles::ROLE_ADMIN, 'subject_type' => 'webhooks', 'subject_key' => '*', 'action' => '*', 'effect' => 'allow', 'fields' => null, 'conditions' => null],
                ['id' => '4', 'role_id' => SystemRoles::ROLE_ADMIN, 'subject_type' => 'system', 'subject_key' => '*', 'action' => 'read', 'effect' => 'allow', 'fields' => null, 'conditions' => null],

                // Developer
                ['id' => '5', 'role_id' => SystemRoles::ROLE_DEVELOPER, 'subject_type' => 'collection', 'subject_key' => '*', 'action' => '*', 'effect' => 'allow', 'fields' => null, 'conditions' => null],
                ['id' => '6', 'role_id' => SystemRoles::ROLE_DEVELOPER, 'subject_type' => 'site', 'subject_key' => '*', 'action' => '*', 'effect' => 'allow', 'fields' => null, 'conditions' => null],
                ['id' => '7', 'role_id' => SystemRoles::ROLE_DEVELOPER, 'subject_type' => 'webhooks', 'subject_key' => '*', 'action' => '*', 'effect' => 'allow', 'fields' => null, 'conditions' => null],
                ['id' => '8', 'role_id' => SystemRoles::ROLE_DEVELOPER, 'subject_type' => 'system', 'subject_key' => '*', 'action' => '*', 'effect' => 'allow', 'fields' => null, 'conditions' => null],

                // Contributor
                ['id' => '9', 'role_id' => SystemRoles::ROLE_CONTRIBUTOR, 'subject_type' => 'collection', 'subject_key' => '*', 'action' => 'create', 'effect' => 'allow', 'fields' => null, 'conditions' => json_encode(['status' => ['draft']])],
                ['id' => '10', 'role_id' => SystemRoles::ROLE_CONTRIBUTOR, 'subject_type' => 'collection', 'subject_key' => '*', 'action' => 'read', 'effect' => 'allow', 'fields' => null, 'conditions' => json_encode(['owner' => 'self'])],
                ['id' => '11', 'role_id' => SystemRoles::ROLE_CONTRIBUTOR, 'subject_type' => 'collection', 'subject_key' => '*', 'action' => 'update', 'effect' => 'allow', 'fields' => null, 'conditions' => json_encode(['owner' => 'self', 'status' => ['draft']])],
                ['id' => '12', 'role_id' => SystemRoles::ROLE_CONTRIBUTOR, 'subject_type' => 'media', 'subject_key' => '*', 'action' => 'create', 'effect' => 'allow', 'fields' => null, 'conditions' => null],
                ['id' => '13', 'role_id' => SystemRoles::ROLE_CONTRIBUTOR, 'subject_type' => 'media', 'subject_key' => '*', 'action' => 'read', 'effect' => 'allow', 'fields' => null, 'conditions' => null],
                ['id' => '14', 'role_id' => SystemRoles::ROLE_CONTRIBUTOR, 'subject_type' => 'site', 'subject_key' => 'navigation', 'action' => 'read', 'effect' => 'allow', 'fields' => null, 'conditions' => null],

                // Viewer
                ['id' => '15', 'role_id' => SystemRoles::ROLE_VIEWER, 'subject_type' => 'collection', 'subject_key' => '*', 'action' => 'read', 'effect' => 'allow', 'fields' => null, 'conditions' => null],
                ['id' => '16', 'role_id' => SystemRoles::ROLE_VIEWER, 'subject_type' => 'taxonomy', 'subject_key' => '*', 'action' => 'read', 'effect' => 'allow', 'fields' => null, 'conditions' => null],
                ['id' => '17', 'role_id' => SystemRoles::ROLE_VIEWER, 'subject_type' => 'term', 'subject_key' => '*', 'action' => 'read', 'effect' => 'allow', 'fields' => null, 'conditions' => null],
                ['id' => '18', 'role_id' => SystemRoles::ROLE_VIEWER, 'subject_type' => 'media', 'subject_key' => '*', 'action' => 'read', 'effect' => 'allow', 'fields' => null, 'conditions' => null],
            ];

            $lastParams = [];
            $stmt->method('execute')->willReturnCallback(function (?array $params = null) use (&$lastParams) {
                $lastParams = $params ?? [];
                return true;
            });
            $stmt->method('fetchAll')->willReturnCallback(function () use (&$lastParams, $allRows) {
                if (empty($lastParams)) {
                    return $allRows;
                }
                return array_values(array_filter($allRows, fn ($r) => in_array($r['role_id'], $lastParams, true)));
            });
            return $stmt;
        });

        $compiler = new PermissionCompiler($connection);
        $this->authorizer = new Authorizer($connection, $compiler);
    }

    public function testMaintenanceAndBackupPermissions(): void
    {
        $admin = Actor::user('admin-1', 'Admin User', [SystemRoles::ROLE_ADMIN]);
        $developer = Actor::user('dev-1', 'Developer User', [SystemRoles::ROLE_DEVELOPER]);
        $viewer = Actor::user('viewer-1', 'Viewer User', [SystemRoles::ROLE_VIEWER]);

        // Maintenance enable/disable (update on system:maintenance)
        $decision = $this->authorizer->can($admin, 'update', new Subject('system', 'maintenance'));
        $this->assertFalse($decision->allowed, "Admin should not be allowed update system:maintenance: {$decision->reason}");
        $this->assertTrue($this->authorizer->can($developer, 'update', new Subject('system', 'maintenance'))->allowed);
        $this->assertFalse($this->authorizer->can($viewer, 'update', new Subject('system', 'maintenance'))->allowed);

        // System backup create (create on system:backup)
        $this->assertFalse($this->authorizer->can($admin, 'create', new Subject('system', 'backup'))->allowed);
        $this->assertTrue($this->authorizer->can($developer, 'create', new Subject('system', 'backup'))->allowed);
        $this->assertFalse($this->authorizer->can($viewer, 'create', new Subject('system', 'backup'))->allowed);

        // System read (status/info)
        $this->assertTrue($this->authorizer->can($admin, 'read', new Subject('system', 'maintenance'))->allowed);
        $this->assertTrue($this->authorizer->can($developer, 'read', new Subject('system', 'maintenance'))->allowed);
    }

    public function testWebhooksAccessNormalizedSingularPlural(): void
    {
        $admin = Actor::user('admin-1', 'Admin User', [SystemRoles::ROLE_ADMIN]);
        $developer = Actor::user('dev-1', 'Developer User', [SystemRoles::ROLE_DEVELOPER]);
        $viewer = Actor::user('viewer-1', 'Viewer User', [SystemRoles::ROLE_VIEWER]);

        // WebhooksController checks new Subject('webhook', '*')
        $this->assertTrue($this->authorizer->can($admin, 'manage', new Subject('webhook', '*'))->allowed);
        $this->assertTrue($this->authorizer->can($developer, 'manage', new Subject('webhook', '*'))->allowed);
        $this->assertFalse($this->authorizer->can($viewer, 'manage', new Subject('webhook', '*'))->allowed);
    }

    public function testSingletonsNavigationRead(): void
    {
        $contributor = Actor::user('contrib-1', 'Contributor User', [SystemRoles::ROLE_CONTRIBUTOR]);
        $viewer = Actor::user('viewer-1', 'Viewer User', [SystemRoles::ROLE_VIEWER]);
        $admin = Actor::user('admin-1', 'Admin User', [SystemRoles::ROLE_ADMIN]);

        // Contributor can read site:navigation
        $this->assertTrue($this->authorizer->can($contributor, 'read', new Subject('site', 'navigation'))->allowed);
        $this->assertTrue($this->authorizer->can($admin, 'read', new Subject('site', 'navigation'))->allowed);

        // Viewer can read collection singletons
        $this->assertTrue($this->authorizer->can($viewer, 'read', new Subject('collection', 'navigation'))->allowed);
    }

    public function testCollectionExportGating(): void
    {
        $viewer = Actor::user('viewer-1', 'Viewer User', [SystemRoles::ROLE_VIEWER]);
        $admin = Actor::user('admin-1', 'Admin User', [SystemRoles::ROLE_ADMIN]);
        $developer = Actor::user('dev-1', 'Developer User', [SystemRoles::ROLE_DEVELOPER]);

        // Viewer has 'read' on collection:posts, but NOT 'export'
        $this->assertTrue($this->authorizer->can($viewer, 'read', new Subject('collection', 'posts'))->allowed);
        $this->assertFalse($this->authorizer->can($viewer, 'export', new Subject('collection', 'posts'))->allowed);

        // Admin and Developer have wildcard/manage on collection:posts, so they can export
        $this->assertTrue($this->authorizer->can($admin, 'export', new Subject('collection', 'posts'))->allowed);
        $this->assertTrue($this->authorizer->can($developer, 'export', new Subject('collection', 'posts'))->allowed);
    }
}
