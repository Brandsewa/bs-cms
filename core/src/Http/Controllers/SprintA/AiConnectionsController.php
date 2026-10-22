<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers\SprintA;

use BsCms\Auth\Actor;
use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Database\Connection;
use BsCms\Http\Controllers\BaseController;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Controller for managing AI OAuth grants, active connections, and defaults.
 */
class AiConnectionsController extends BaseController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Authorizer $authorizer
    ) {
    }

    /**
     * GET /api/v1/ai/grants
     */
    public function listGrants(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('ai', '*'));
        if ($permError !== null) {
            return $permError;
        }

        $prefix = $this->connection->getPrefix();
        $stmt = $this->connection->execute(
            "SELECT g.`id`, g.`user_id`, g.`client_id`, g.`role_id`, g.`scopes`, g.`ai_mode`,
                    g.`created_at`, g.`last_used_at`, g.`revoked_at`,
                    c.`name` as `client_name`, c.`client_id` as `oauth_client_id`,
                    u.`email` as `user_email`, r.`name` as `role_name`
             FROM `{$prefix}oauth_grants` g
             JOIN `{$prefix}oauth_clients` c ON g.`client_id` = c.`id`
             JOIN `{$prefix}users` u ON g.`user_id` = u.`id`
             JOIN `{$prefix}roles` r ON g.`role_id` = r.`id`
             ORDER BY g.`created_at` DESC"
        );
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            if (is_string($row['scopes'])) {
                $row['scopes'] = json_decode($row['scopes'], true) ?? [];
            }
        }
        unset($row);

        return $this->json(['items' => array_values($rows)]);
    }

    /**
     * DELETE /api/v1/ai/grants/{id}
     */
    public function revokeGrant(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('ai', '*'));
        if ($permError !== null) {
            return $permError;
        }

        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $prefix = $this->connection->getPrefix();

        $stmt = $this->connection->execute("SELECT * FROM `{$prefix}oauth_grants` WHERE `id` = ?", [$id]);
        $grant = $stmt->fetch();
        if (!$grant) {
            return $this->problem(404, 'Not Found', "AI Grant '{$id}' not found.");
        }

        $now = date('Y-m-d H:i:s.v');
        $this->connection->execute(
            "UPDATE `{$prefix}oauth_grants` SET `revoked_at` = ? WHERE `id` = ?",
            [$now, $id]
        );

        // Also revoke child tokens
        $this->connection->execute(
            "UPDATE `{$prefix}oauth_access_tokens` SET `revoked_at` = ? WHERE `grant_id` = ?",
            [$now, $id]
        );
        $this->connection->execute(
            "UPDATE `{$prefix}oauth_refresh_tokens` SET `revoked_at` = ? WHERE `grant_id` = ?",
            [$now, $id]
        );

        return $this->json(['status' => 'grant_revoked', 'id' => $id]);
    }
}
