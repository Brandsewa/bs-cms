<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Database\Connection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class JobsController extends BaseController
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * @param array<string, mixed> $args
     */
    public function get(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $prefix = $this->connection->getPrefix();

        $stmt = $this->connection->execute(
            "SELECT `id`, `queue`, `type`, `status`, `attempts`, `max_attempts`, `available_at`, `reserved_at`, `last_error`, `created_at`
             FROM `{$prefix}jobs` WHERE `id` = ?",
            [$id]
        );
        $job = $stmt->fetch();

        if (!$job) {
            return $this->problem(404, 'Not Found', "Job '{$id}' not found.");
        }

        return $this->json($job);
    }
}
