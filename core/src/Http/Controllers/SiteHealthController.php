<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Health\SiteHealthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class SiteHealthController extends BaseController
{
    public function __construct(
        private readonly SiteHealthService $healthService,
        private readonly Authorizer $authorizer
    ) {
    }

    /**
     * GET /api/v1/site-health
     */
    public function status(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('site', 'health'));
        if ($permError !== null) {
            return $permError;
        }

        $report = $this->healthService->scan();
        return $this->json($report);
    }

    /**
     * POST /api/v1/site-health/run
     */
    public function run(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('site', 'health'));
        if ($permError !== null) {
            return $permError;
        }

        $report = $this->healthService->scan();
        return $this->json($report);
    }
}
