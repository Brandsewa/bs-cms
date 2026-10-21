<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Ai\AiAccessService;
use BsCms\Ai\AiDisconnectPresenter;
use BsCms\Ai\AiReconnectPresenter;
use BsCms\Ai\AiStatusPresenter;
use BsCms\Auth\Actor;
use BsCms\Auth\PasswordHasher;
use BsCms\Users\UserRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * AiController: Endpoints for managing AI integration, the AI kill switch, and status.
 */
final class AiController extends BaseController
{
    public function __construct(
        private readonly AiAccessService $aiService,
        private readonly ?UserRepository $userRepository = null,
        private readonly ?PasswordHasher $passwordHasher = null
    ) {
    }

    /**
     * GET /api/v1/ai/status
     */
    public function status(ServerRequestInterface $request): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        if ($actor->type === Actor::TYPE_OAUTH || $actor->type === Actor::TYPE_AI_CLIENT) {
            return $this->problem(403, 'Forbidden', 'OAuth and MCP tokens cannot access AI management.');
        }

        if (!$this->isAdminOrOwner($actor)) {
            return $this->problem(403, 'Forbidden', 'Only administrators and owners can view AI connection status.');
        }

        $status = $this->aiService->getStatus();

        return $this->json(AiStatusPresenter::present($status), 200, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * POST /api/v1/ai/disconnect (Kill switch)
     */
    public function disconnect(ServerRequestInterface $request): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        if ($actor->type === Actor::TYPE_OAUTH || $actor->type === Actor::TYPE_AI_CLIENT) {
            return $this->problem(403, 'Forbidden', 'OAuth and MCP tokens cannot access AI management.');
        }

        if (!$this->isAdminOrOwner($actor)) {
            return $this->problem(403, 'Forbidden', 'Only administrators and owners can disconnect AI access.');
        }

        $stepUpError = $this->verifyStepUpOrPassword($request, $actor);
        if ($stepUpError !== null) {
            return $stepUpError;
        }

        $ip = $this->resolveClientIp($request);
        $userAgent = $request->getHeaderLine('User-Agent');

        $result = $this->aiService->disconnect($actor, $ip, $userAgent);

        return $this->json(AiDisconnectPresenter::present($result), 200, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * POST /api/v1/ai/reconnect (Re-enable AI access)
     */
    public function reconnect(ServerRequestInterface $request): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        if ($actor->type === Actor::TYPE_OAUTH || $actor->type === Actor::TYPE_AI_CLIENT) {
            return $this->problem(403, 'Forbidden', 'OAuth and MCP tokens cannot access AI management.');
        }

        if (!$this->isAdminOrOwner($actor)) {
            return $this->problem(403, 'Forbidden', 'Only administrators and owners can re-enable AI access.');
        }

        $stepUpError = $this->verifyStepUpOrPassword($request, $actor);
        if ($stepUpError !== null) {
            return $stepUpError;
        }

        $ip = $this->resolveClientIp($request);
        $userAgent = $request->getHeaderLine('User-Agent');

        $result = $this->aiService->reconnect($actor, $ip, $userAgent);

        return $this->json(AiReconnectPresenter::present($result), 200, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    private function isAdminOrOwner(Actor $actor): bool
    {
        return $actor->hasRole(\BsCms\Auth\SystemRoles::ROLE_OWNER)
            || $actor->hasRole(\BsCms\Auth\SystemRoles::ROLE_ADMIN)
            || $actor->hasRole('owner')
            || $actor->hasRole('admin');
    }

    private function verifyStepUpOrPassword(ServerRequestInterface $request, Actor $actor): ?ResponseInterface
    {
        $stepUpError = $this->requireStepUp($request);
        if ($stepUpError === null) {
            return null;
        }

        // Check if inline password was provided in the JSON body
        $body = $this->getBody($request);
        $password = isset($body['password']) ? (string) $body['password'] : '';
        if ($password !== '' && $this->userRepository !== null && $this->passwordHasher !== null) {
            $user = $this->userRepository->findById($actor->id);
            if ($user !== null && $this->passwordHasher->verify($password, (string) ($user['password_hash'] ?? ''))) {
                return null;
            }
        }

        return $stepUpError;
    }

    private function resolveClientIp(ServerRequestInterface $request): string
    {
        $server = $request->getServerParams();
        if (!empty($server['REMOTE_ADDR'])) {
            return (string) $server['REMOTE_ADDR'];
        }
        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        if ($forwarded !== '') {
            $parts = explode(',', $forwarded);
            return trim($parts[0]);
        }
        return '127.0.0.1';
    }
}
