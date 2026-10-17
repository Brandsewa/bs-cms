<?php

declare(strict_types=1);

namespace BsCms\Migrations;

use BsCms\Database\Migration;
use BsCms\Security\Ulid;
use BsCms\Support\Clock;

/**
 * Migration 0003: Seed system roles and default permissions.
 * Adheres strictly to docs/03-auth-and-email.md Section 2.3.
 */
final class SeedSystemRoles extends Migration
{
    public const ROLE_OWNER = '01JB0000000000000000000001';
    public const ROLE_ADMIN = '01JB0000000000000000000002';
    public const ROLE_DEVELOPER = '01JB0000000000000000000003';
    public const ROLE_EDITOR = '01JB0000000000000000000004';
    public const ROLE_AUTHOR = '01JB0000000000000000000005';
    public const ROLE_CONTRIBUTOR = '01JB0000000000000000000006';
    public const ROLE_VIEWER = '01JB0000000000000000000007';
    public const ROLE_AI_DRAFT = '01JB0000000000000000000008';
    public const ROLE_AI_PUBLISHER = '01JB0000000000000000000009';

    public function up(): void
    {
        $rolesTable = $this->connection->prefixTable('roles');
        $permsTable = $this->connection->prefixTable('role_permissions');
        $pdo = $this->connection->getPdo();
        $now = Clock::sql(Clock::now());

        $roles = [
            [
                'id' => self::ROLE_OWNER,
                'handle' => 'owner',
                'name' => 'Owner',
                'description' => 'Full ownership with all permissions across all subjects',
                'is_system' => 1,
                'is_ai' => 0,
            ],
            [
                'id' => self::ROLE_ADMIN,
                'handle' => 'admin',
                'name' => 'Administrator',
                'description' => 'Administrator with access to all content, media, site and settings except owner role management and security keys',
                'is_system' => 1,
                'is_ai' => 0,
            ],
            [
                'id' => self::ROLE_DEVELOPER,
                'handle' => 'developer',
                'name' => 'Developer',
                'description' => 'Developer with schema, API, webhook, plugin and system management access',
                'is_system' => 1,
                'is_ai' => 0,
            ],
            [
                'id' => self::ROLE_EDITOR,
                'handle' => 'editor',
                'name' => 'Editor',
                'description' => 'Can edit, publish, unpublish, schedule, and soft-delete content across all collections, manage terms and media',
                'is_system' => 1,
                'is_ai' => 0,
            ],
            [
                'id' => self::ROLE_AUTHOR,
                'handle' => 'author',
                'name' => 'Author',
                'description' => 'Can create and edit own entries and submit for review, assign terms, and manage own media',
                'is_system' => 1,
                'is_ai' => 0,
            ],
            [
                'id' => self::ROLE_CONTRIBUTOR,
                'handle' => 'contributor',
                'name' => 'Contributor',
                'description' => 'Can create drafts only and upload media',
                'is_system' => 1,
                'is_ai' => 0,
            ],
            [
                'id' => self::ROLE_VIEWER,
                'handle' => 'viewer',
                'name' => 'Viewer',
                'description' => 'Read-only access to content, taxonomies and media',
                'is_system' => 1,
                'is_ai' => 0,
            ],
            [
                'id' => self::ROLE_AI_DRAFT,
                'handle' => 'ai_draft',
                'name' => 'AI Draft Agent',
                'description' => 'AI client role: create and update drafts only, assign terms, upload media',
                'is_system' => 1,
                'is_ai' => 1,
            ],
            [
                'id' => self::ROLE_AI_PUBLISHER,
                'handle' => 'ai_publisher',
                'name' => 'AI Publisher Agent',
                'description' => 'AI client role: create, update, publish with approval, manage terms with approval, upload media',
                'is_system' => 1,
                'is_ai' => 1,
            ],
        ];

        $roleStmt = $pdo->prepare("INSERT INTO `{$rolesTable}` (`id`, `handle`, `name`, `description`, `is_system`, `is_ai`, `created_at`, `updated_at`)
            VALUES (:id, :handle, :name, :description, :is_system, :is_ai, :created_at, :updated_at)");

        foreach ($roles as $r) {
            $roleStmt->execute([
                'id' => $r['id'],
                'handle' => $r['handle'],
                'name' => $r['name'],
                'description' => $r['description'],
                'is_system' => $r['is_system'],
                'is_ai' => $r['is_ai'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permStmt = $pdo->prepare("INSERT INTO `{$permsTable}` (`id`, `role_id`, `subject_type`, `subject_key`, `action`, `effect`, `fields`, `conditions`, `created_at`)
            VALUES (:id, :role_id, :subject_type, :subject_key, :action, :effect, :fields, :conditions, :created_at)");

        $permissions = [
            // 1. Owner: wildcard all
            [self::ROLE_OWNER, '*', '*', '*', 'allow', null, null],

            // 2. Admin: all except owner role and security keys
            [self::ROLE_ADMIN, 'collection', '*', '*', 'allow', null, null],
            [self::ROLE_ADMIN, 'taxonomy', '*', '*', 'allow', null, null],
            [self::ROLE_ADMIN, 'media', '*', '*', 'allow', null, null],
            [self::ROLE_ADMIN, 'site', '*', '*', 'allow', null, null],
            [self::ROLE_ADMIN, 'redirects', '*', '*', 'allow', null, null],
            [self::ROLE_ADMIN, 'plugins', '*', '*', 'allow', null, null],
            [self::ROLE_ADMIN, 'webhooks', '*', '*', 'allow', null, null],
            [self::ROLE_ADMIN, 'ai', '*', '*', 'allow', null, null],
            [self::ROLE_ADMIN, 'schema', '*', 'read', 'allow', null, null],
            [self::ROLE_ADMIN, 'settings', '*', '*', 'allow', null, null],
            [self::ROLE_ADMIN, 'settings', 'security_keys', 'update', 'deny', null, null],
            [self::ROLE_ADMIN, 'users', '*', '*', 'allow', null, null],
            [self::ROLE_ADMIN, 'roles', '*', '*', 'allow', null, null],
            [self::ROLE_ADMIN, 'roles', 'owner', '*', 'deny', null, null],
            [self::ROLE_ADMIN, 'audit', '*', 'read', 'allow', null, null],
            [self::ROLE_ADMIN, 'system', '*', 'read', 'allow', null, null],

            // 3. Developer
            [self::ROLE_DEVELOPER, 'collection', '*', '*', 'allow', null, null],
            [self::ROLE_DEVELOPER, 'taxonomy', '*', '*', 'allow', null, null],
            [self::ROLE_DEVELOPER, 'media', '*', '*', 'allow', null, null],
            [self::ROLE_DEVELOPER, 'site', '*', '*', 'allow', null, null],
            [self::ROLE_DEVELOPER, 'redirects', '*', '*', 'allow', null, null],
            [self::ROLE_DEVELOPER, 'plugins', '*', '*', 'allow', null, null],
            [self::ROLE_DEVELOPER, 'webhooks', '*', '*', 'allow', null, null],
            [self::ROLE_DEVELOPER, 'ai', '*', '*', 'allow', null, null],
            [self::ROLE_DEVELOPER, 'schema', '*', '*', 'allow', null, null],
            [self::ROLE_DEVELOPER, 'settings', '*', 'read', 'allow', null, null],
            [self::ROLE_DEVELOPER, 'users', '*', 'read', 'allow', null, null],
            [self::ROLE_DEVELOPER, 'roles', '*', 'read', 'allow', null, null],
            [self::ROLE_DEVELOPER, 'audit', '*', 'read', 'allow', null, null],
            [self::ROLE_DEVELOPER, 'system', '*', '*', 'allow', null, null],

            // 4. Editor
            [self::ROLE_EDITOR, 'collection', '*', 'read', 'allow', null, null],
            [self::ROLE_EDITOR, 'collection', '*', 'create', 'allow', null, null],
            [self::ROLE_EDITOR, 'collection', '*', 'update', 'allow', null, null],
            [self::ROLE_EDITOR, 'collection', '*', 'publish', 'allow', null, null],
            [self::ROLE_EDITOR, 'collection', '*', 'unpublish', 'allow', null, null],
            [self::ROLE_EDITOR, 'collection', '*', 'schedule', 'allow', null, null],
            [self::ROLE_EDITOR, 'collection', '*', 'delete', 'allow', null, null],
            [self::ROLE_EDITOR, 'taxonomy', '*', 'manage', 'allow', null, null],
            [self::ROLE_EDITOR, 'taxonomy', '*', 'read', 'allow', null, null],
            [self::ROLE_EDITOR, 'taxonomy', '*', 'create', 'allow', null, null],
            [self::ROLE_EDITOR, 'taxonomy', '*', 'update', 'allow', null, null],
            [self::ROLE_EDITOR, 'taxonomy', '*', 'delete', 'allow', null, null],
            [self::ROLE_EDITOR, 'media', '*', '*', 'allow', null, null],
            [self::ROLE_EDITOR, 'site', 'navigation', 'read', 'allow', null, null],
            [self::ROLE_EDITOR, 'site', 'navigation', 'update', 'allow', null, null],

            // 5. Author
            [self::ROLE_AUTHOR, 'collection', '*', 'create', 'allow', null, null],
            [self::ROLE_AUTHOR, 'collection', '*', 'read', 'allow', null, null],
            [self::ROLE_AUTHOR, 'collection', '*', 'update', 'allow', null, json_encode(['owner' => 'self'])],
            [self::ROLE_AUTHOR, 'taxonomy', '*', 'read', 'allow', null, null],
            [self::ROLE_AUTHOR, 'taxonomy', '*', 'create', 'allow', null, null],
            [self::ROLE_AUTHOR, 'media', '*', 'create', 'allow', null, null],
            [self::ROLE_AUTHOR, 'media', '*', 'read', 'allow', null, null],
            [self::ROLE_AUTHOR, 'media', '*', 'update', 'allow', null, json_encode(['owner' => 'self'])],
            [self::ROLE_AUTHOR, 'media', '*', 'delete', 'allow', null, json_encode(['owner' => 'self'])],

            // 6. Contributor
            [self::ROLE_CONTRIBUTOR, 'collection', '*', 'create', 'allow', null, json_encode(['status' => ['draft']])],
            [self::ROLE_CONTRIBUTOR, 'collection', '*', 'read', 'allow', null, json_encode(['owner' => 'self'])],
            [self::ROLE_CONTRIBUTOR, 'collection', '*', 'update', 'allow', null, json_encode(['owner' => 'self', 'status' => ['draft']])],
            [self::ROLE_CONTRIBUTOR, 'taxonomy', '*', 'read', 'allow', null, null],
            [self::ROLE_CONTRIBUTOR, 'media', '*', 'create', 'allow', null, null],
            [self::ROLE_CONTRIBUTOR, 'media', '*', 'read', 'allow', null, null],

            // 7. Viewer
            [self::ROLE_VIEWER, 'collection', '*', 'read', 'allow', null, null],
            [self::ROLE_VIEWER, 'taxonomy', '*', 'read', 'allow', null, null],
            [self::ROLE_VIEWER, 'media', '*', 'read', 'allow', null, null],

            // 8. AI Draft
            [self::ROLE_AI_DRAFT, 'collection', '*', 'create', 'allow', null, json_encode(['status' => ['draft']])],
            [self::ROLE_AI_DRAFT, 'collection', '*', 'update', 'allow', null, json_encode(['status' => ['draft']])],
            [self::ROLE_AI_DRAFT, 'collection', '*', 'read', 'allow', null, null],
            [self::ROLE_AI_DRAFT, 'taxonomy', '*', 'read', 'allow', null, null],
            [self::ROLE_AI_DRAFT, 'media', '*', 'create', 'allow', null, null],
            [self::ROLE_AI_DRAFT, 'media', '*', 'read', 'allow', null, null],

            // 9. AI Publisher
            [self::ROLE_AI_PUBLISHER, 'collection', '*', 'create', 'allow', null, null],
            [self::ROLE_AI_PUBLISHER, 'collection', '*', 'update', 'allow', null, null],
            [self::ROLE_AI_PUBLISHER, 'collection', '*', 'publish', 'allow', null, null],
            [self::ROLE_AI_PUBLISHER, 'collection', '*', 'read', 'allow', null, null],
            [self::ROLE_AI_PUBLISHER, 'taxonomy', '*', 'manage', 'allow', null, null],
            [self::ROLE_AI_PUBLISHER, 'taxonomy', '*', 'read', 'allow', null, null],
            [self::ROLE_AI_PUBLISHER, 'media', '*', 'create', 'allow', null, null],
            [self::ROLE_AI_PUBLISHER, 'media', '*', 'read', 'allow', null, null],
            [self::ROLE_AI_PUBLISHER, 'site', 'navigation', 'update', 'allow', null, null],
            [self::ROLE_AI_PUBLISHER, 'site', 'navigation', 'read', 'allow', null, null],
            [self::ROLE_AI_PUBLISHER, 'schema', '*', 'read', 'allow', null, null],
        ];

        foreach ($permissions as $p) {
            $permStmt->execute([
                'id' => Ulid::generate(),
                'role_id' => $p[0],
                'subject_type' => $p[1],
                'subject_key' => $p[2],
                'action' => $p[3],
                'effect' => $p[4],
                'fields' => $p[5],
                'conditions' => $p[6],
                'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $rolesTable = $this->connection->prefixTable('roles');
        $permsTable = $this->connection->prefixTable('role_permissions');
        $pdo = $this->connection->getPdo();

        $roleIds = [
            self::ROLE_OWNER,
            self::ROLE_ADMIN,
            self::ROLE_DEVELOPER,
            self::ROLE_EDITOR,
            self::ROLE_AUTHOR,
            self::ROLE_CONTRIBUTOR,
            self::ROLE_VIEWER,
            self::ROLE_AI_DRAFT,
            self::ROLE_AI_PUBLISHER,
        ];

        $in = "'" . implode("','", $roleIds) . "'";
        $pdo->exec("DELETE FROM `{$permsTable}` WHERE `role_id` IN ({$in})");
        $pdo->exec("DELETE FROM `{$rolesTable}` WHERE `id` IN ({$in})");
    }
}
