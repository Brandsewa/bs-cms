<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Publishing\BuildTokenPresenter;
use BsCms\Publishing\BuildTokenService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class BuildTokensController extends BaseController
{
    public function __construct(
        private readonly BuildTokenService $tokenService,
        private readonly Authorizer $authorizer
    ) {
    }

    /**
     * GET /api/v1/tokens/build
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('settings', 'publishing'));
        if ($permError !== null) {
            return $permError;
        }

        $tokens = $this->tokenService->list();
        return $this->json(BuildTokenPresenter::presentCollection($tokens));
    }

    /**
     * POST /api/v1/tokens/build
     * Returns raw token secret once along with presented metadata.
     */
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'manage', new Subject('settings', 'publishing'));
        if ($permError !== null) {
            return $permError;
        }

        $stepUpError = $this->requireStepUp($request);
        if ($stepUpError !== null) {
            return $stepUpError;
        }

        $body = $this->getBody($request);
        if (!isset($body['name']) || !is_string($body['name'])) {
            return $this->problem(422, 'Unprocessable Content', 'Token name must be a string.');
        }

        $name = trim($body['name']);
        if ($name === '') {
            return $this->problem(422, 'Unprocessable Content', 'Token name cannot be empty.');
        }

        if (mb_strlen($name) > 120) {
            return $this->problem(422, 'Unprocessable Content', 'Token name cannot exceed 120 characters.');
        }

        $actor = $this->getActor($request);
        $result = $this->tokenService->create($name, $actor);

        return $this->json([
            'id' => $result['id'],
            'name' => $result['name'],
            'token' => $result['token'],
            'token_prefix' => $result['token_prefix'],
            'created_at' => $result['created_at'],
        ], 201);
    }

    /**
     * DELETE /api/v1/tokens/build/{id}
     *
     * @param array<string, mixed> $args
     */
    public function revoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'manage', new Subject('settings', 'publishing'));
        if ($permError !== null) {
            return $permError;
        }

        $stepUpError = $this->requireStepUp($request);
        if ($stepUpError !== null) {
            return $stepUpError;
        }

        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        if ($id === '') {
            return $this->problem(404, 'Not Found', 'Build token not found.');
        }

        $actor = $this->getActor($request);
        $revoked = $this->tokenService->revoke($id, $actor);

        if (!$revoked) {
            return $this->problem(404, 'Not Found', 'Build token not found or already revoked.');
        }

        return $this->json(['status' => 'token_revoked']);
    }
}
