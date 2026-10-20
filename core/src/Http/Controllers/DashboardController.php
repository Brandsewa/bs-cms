<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Auth\Actor;
use BsCms\Dashboard\DashboardService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Controller for GET /api/v1/dashboard.
 * Serves role-aware aggregate metrics, queues, health, and status.
 * Rejects anonymous visitors (401) and OAuth/MCP tokens (403).
 */
class DashboardController extends BaseController
{
    public function __construct(
        private readonly DashboardService $dashboardService
    ) {
    }

    /**
     * GET /api/v1/dashboard
     */
    public function get(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->getActor($request);

        // 1. Enforce authentication
        if ($actor->type === Actor::TYPE_ANONYMOUS) {
            return $this->problem(401, 'Unauthorized', 'Authentication is required.');
        }

        // 2. Reject OAuth and MCP tokens (REST session or personal API tokens only)
        if ($actor->type === Actor::TYPE_OAUTH || $actor->type === Actor::TYPE_AI_CLIENT) {
            return $this->problem(403, 'Forbidden', 'OAuth and MCP tokens cannot access the administrative dashboard.');
        }

        // 3. Compute role-aware dashboard payload
        $data = $this->dashboardService->getDashboardData($actor);

        return $this->json($data, 200, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }
}
