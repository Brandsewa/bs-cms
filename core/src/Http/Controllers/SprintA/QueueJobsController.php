<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers\SprintA;

use BsCms\Auth\Actor;
use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Database\Connection;
use BsCms\Http\Controllers\BaseController;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Controller for developer queue inspection and failed job retries.
 */
class QueueJobsController extends BaseController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Authorizer $authorizer
    ) {
    }

    /**
     * GET /api/v1/jobs
     */
    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('settings', 'developer'));
        if ($permError !== null) {
            return $permError;
        }

        $params = $request->getQueryParams();
        $status = isset($params['status']) && is_string($params['status']) ? trim($params['status']) : null;
        $queue = isset($params['queue']) && is_string($params['queue']) ? trim($params['queue']) : null;
        $limit = isset($params['limit']) ? max(1, min(100, (int) $params['limit'])) : 50;

        $prefix = $this->connection->getPrefix();
        $where = [];
        $sqlParams = [];

        if ($status !== null && $status !== '') {
            $where[] = '`status` = ?';
            $sqlParams[] = $status;
        }
        if ($queue !== null && $queue !== '') {
            $where[] = '`queue` = ?';
            $sqlParams[] = $queue;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = $this->connection->execute(
            "SELECT `id`, `queue`, `type`, `status`, `attempts`, `max_attempts`, `available_at`, `reserved_at`, `last_error`, `created_at`
             FROM `{$prefix}jobs` {$whereClause} ORDER BY `id` DESC LIMIT {$limit}",
            $sqlParams
        );
        $rows = $stmt->fetchAll();

        // Get status counts
        $countsStmt = $this->connection->execute(
            "SELECT `status`, COUNT(*) as `cnt` FROM `{$prefix}jobs` GROUP BY `status`"
        );
        $countsRows = $countsStmt->fetchAll();
        $counts = ['queued' => 0, 'reserved' => 0, 'failed' => 0, 'completed' => 0];
        foreach ($countsRows as $c) {
            $st = (string) $c['status'];
            $counts[$st] = (int) $c['cnt'];
        }

        return $this->json([
            'items' => array_values($rows),
            'counts' => $counts,
            'total' => count($rows),
        ]);
    }

    /**
     * POST /api/v1/jobs/{id}/retry
     */
    public function retry(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('settings', 'developer'));
        if ($permError !== null) {
            return $permError;
        }

        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $prefix = $this->connection->getPrefix();

        $stmt = $this->connection->execute(
            "SELECT * FROM `{$prefix}jobs` WHERE `id` = ?",
            [$id]
        );
        $job = $stmt->fetch();
        if (!$job) {
            return $this->problem(404, 'Not Found', "Job '{$id}' not found.");
        }

        $now = date('Y-m-d H:i:s.v');
        $this->connection->execute(
            "UPDATE `{$prefix}jobs` SET `status` = 'queued', `attempts` = 0, `available_at` = ?, `reserved_at` = NULL, `last_error` = NULL WHERE `id` = ?",
            [$now, $id]
        );

        $stmt = $this->connection->execute("SELECT * FROM `{$prefix}jobs` WHERE `id` = ?", [$id]);
        $updated = $stmt->fetch();

        return $this->json([
            'status' => 'queued_for_retry',
            'job' => $updated,
        ]);
    }
}
