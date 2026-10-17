<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Auth\Actor;
use BsCms\Auth\TwoFactorService;
use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Http\JsonResponse;
use BsCms\Http\ProblemDetails;
use BsCms\Support\Json;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

abstract class BaseController
{
    /**
     * @param array<string, mixed>|list<mixed> $data
     * @param array<string, string|list<string>> $headers
     */
    protected function json(array $data, int $status = 200, array $headers = []): ResponseInterface
    {
        $headers['Cache-Control'] = 'no-store';
        return JsonResponse::create($data, $status, $headers);
    }

    /**
     * @param array<string, list<string>>|null $errors
     */
    protected function problem(
        int $status,
        string $title,
        ?string $detail = null,
        ?string $type = null,
        ?array $errors = null
    ): ResponseInterface {
        $problem = new ProblemDetails(
            $status,
            $title,
            $detail,
            $type ?? 'about:blank',
            null,
            $errors
        );

        return $problem->toResponse()->withHeader('Cache-Control', 'no-store');
    }

    protected function getActor(ServerRequestInterface $request): Actor
    {
        $actor = $request->getAttribute('actor');
        if ($actor instanceof Actor) {
            return $actor;
        }

        return Actor::anonymous();
    }

    protected function requireAuth(ServerRequestInterface $request): ?ResponseInterface
    {
        $actor = $this->getActor($request);
        if ($actor->type === Actor::TYPE_ANONYMOUS) {
            return $this->problem(401, 'Unauthorized', 'Authentication is required.');
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $entity
     */
    protected function requirePermission(
        Authorizer $authorizer,
        ServerRequestInterface $request,
        string $action,
        Subject $subject,
        ?array $entity = null
    ): ?ResponseInterface {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        $decision = $authorizer->can($actor, $action, $subject, $entity);

        if (!$decision->allowed) {
            $reason = $decision->reason !== '' ? $decision->reason : 'You do not have permission for this action.';
            return $this->problem(403, 'Forbidden', $reason);
        }

        return null;
    }

    protected function requireStepUp(ServerRequestInterface $request): ?ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        /** @var array<string, mixed>|null $session */
        $session = $request->getAttribute('session');
        if ($session === null) {
            return $this->problem(403, 'Step-Up Authentication Required', 'Step-up re-authentication is required for this operation.');
        }

        $mfaPassedAt = $session['mfa_passed_at'] ?? null;
        if ($mfaPassedAt === null) {
            return $this->problem(403, 'Step-Up Authentication Required', 'Step-up re-authentication is required for this operation.');
        }

        $ts = is_string($mfaPassedAt) ? strtotime($mfaPassedAt) : false;
        if ($ts === false) {
            return $this->problem(403, 'Step-Up Authentication Required', 'Step-up re-authentication is required for this operation.');
        }

        $twoFactor = new TwoFactorService();
        if (!$twoFactor->isStepUpValid($ts)) {
            return $this->problem(403, 'Step-Up Authentication Required', 'Step-up re-authentication has expired (10 minute limit).');
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getBody(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed)) {
            return $parsed;
        }

        $contents = (string) $request->getBody();
        if ($contents !== '') {
            $decoded = Json::decode($contents);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
