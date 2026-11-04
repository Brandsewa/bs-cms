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

        $report = $this->healthService->scan(force: true);
        return $this->json($report);
    }

    /**
     * POST /api/v1/site-health/dismiss — hide issues until restored.
     * Body: { keys: ["rule_id|entity_id", ...] }
     */
    public function dismiss(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('site', 'health'));
        if ($permError !== null) {
            return $permError;
        }

        $body = $this->getJsonBody($request);
        $keys = is_array($body['keys'] ?? null) ? array_values(array_filter(array_map('strval', $body['keys']))) : [];
        if ($keys === []) {
            return $this->problem(422, 'Unprocessable Content', 'Provide a non-empty keys array.');
        }
        $this->healthService->dismissKeys($keys);
        return $this->json(['dismissed' => count($keys)]);
    }

    /**
     * POST /api/v1/site-health/restore — un-dismiss previously hidden issues.
     * Body: { keys: [...] }
     */
    public function restore(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('site', 'health'));
        if ($permError !== null) {
            return $permError;
        }

        $body = $this->getJsonBody($request);
        $keys = is_array($body['keys'] ?? null) ? array_values(array_filter(array_map('strval', $body['keys']))) : [];
        if ($keys === []) {
            return $this->problem(422, 'Unprocessable Content', 'Provide a non-empty keys array.');
        }
        $this->healthService->restoreKeys($keys);
        return $this->json(['restored' => count($keys)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function getJsonBody(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed)) {
            return $parsed;
        }
        $raw = (string) $request->getBody();
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
