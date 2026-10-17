<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Auth\ApiTokenService;
use BsCms\Support\Clock;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Controller for managing personal access tokens and API tokens.
 */
class TokensController extends BaseController
{
    public function __construct(
        private readonly ApiTokenService $tokenService
    ) {
    }

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        $tokens = $this->tokenService->listForUser($actor->id);

        return $this->json(['items' => $tokens]);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $stepUpError = $this->requireStepUp($request);
        if ($stepUpError !== null) {
            return $stepUpError;
        }

        $actor = $this->getActor($request);
        $body = $this->getBody($request);
        $name = (string) ($body['name'] ?? '');

        if ($name === '') {
            return $this->problem(422, 'Unprocessable Content', 'Token name is required.');
        }

        $roleId = isset($body['role_id']) && is_string($body['role_id']) ? $body['role_id'] : null;
        /** @var array<int, string> $scopes */
        $scopes = isset($body['scopes']) && is_array($body['scopes']) ? $body['scopes'] : ['*'];
        $aiMode = (string) ($body['ai_mode'] ?? 'draft_only');

        $expiresAt = null;
        if (isset($body['expires_in_days']) && is_numeric($body['expires_in_days'])) {
            $days = (int) $body['expires_in_days'];
            if ($days > 0) {
                $mod = Clock::now()->modify("+{$days} days");
                if ($mod !== false) {
                    $expiresAt = $mod;
                }
            }
        }

        $tokenData = $this->tokenService->create(
            userId: $actor->id,
            name: $name,
            roleId: $roleId,
            scopes: $scopes,
            aiMode: $aiMode,
            expiresAt: $expiresAt
        );

        return $this->json($tokenData, 201);
    }

    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        $id = (string) $request->getAttribute('id', '');

        $revoked = $this->tokenService->revoke($id, $actor->id);
        if (!$revoked) {
            return $this->problem(404, 'Not Found', 'Token not found or already revoked.');
        }

        return $this->json(['status' => 'token_revoked']);
    }
}
