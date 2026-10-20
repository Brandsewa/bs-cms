<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Auth\Actor;
use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\PendingChanges\Exceptions\ConflictException;
use BsCms\PendingChanges\Exceptions\NotFoundException;
use BsCms\PendingChanges\PendingChangesService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final class PendingChangesController extends BaseController
{
    public function __construct(
        private readonly PendingChangesService $service,
        private readonly Authorizer $authorizer
    ) {
    }

    /**
     * GET /api/v1/pending-changes
     */
    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }
        $actor = $this->getActor($request);

        $decision = $this->authorizer->can($actor, 'read', new Subject('pending_changes', '*'));
        if (!$decision->allowed) {
            $auditDecision = $this->authorizer->can($actor, 'read', new Subject('audit', '*'));
            if (!$auditDecision->allowed) {
                return $this->forbidden("You do not have permission to view pending changes: {$decision->reason}");
            }
        }

        $params = $request->getQueryParams();
        $status = isset($params['status']) && is_string($params['status']) ? trim($params['status']) : null;
        $operation = isset($params['operation']) && is_string($params['operation']) ? trim($params['operation']) : null;
        $limit = isset($params['limit']) && is_numeric($params['limit']) ? (int) $params['limit'] : 20;
        $offset = isset($params['offset']) && is_numeric($params['offset']) ? (int) $params['offset'] : 0;

        $filters = [];
        if ($status !== null && $status !== '') {
            $filters['status'] = $status;
        }
        if ($operation !== null && $operation !== '') {
            $filters['operation'] = $operation;
        }

        $result = $this->service->list($filters, $limit, $offset);

        return $this->json([
            'items' => $result['items'],
            'total' => $result['total'],
            'limit' => max(1, min(100, $limit)),
            'offset' => max(0, $offset),
        ]);
    }

    /**
     * GET /api/v1/pending-changes/{id}
     *
     * @param array<string, string> $args
     */
    public function get(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }
        $actor = $this->getActor($request);

        $decision = $this->authorizer->can($actor, 'read', new Subject('pending_changes', '*'));
        if (!$decision->allowed) {
            $auditDecision = $this->authorizer->can($actor, 'read', new Subject('audit', '*'));
            if (!$auditDecision->allowed) {
                return $this->forbidden("You do not have permission to view pending changes: {$decision->reason}");
            }
        }

        $id = $args['id'] ?? '';
        $item = $this->service->get($id);

        if ($item === null) {
            return $this->notFound("Pending change '{$id}' not found.");
        }

        return $this->json($item);
    }

    /**
     * POST /api/v1/pending-changes/{id}/approve
     *
     * @param array<string, string> $args
     */
    public function approve(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }
        $actor = $this->getActor($request);

        // Must be a human user (or system), not an AI/OAuth client
        if (!$actor->isUser() && !$actor->isSystem()) {
            return $this->forbidden('Only human users may approve pending changes.');
        }

        $id = $args['id'] ?? '';
        $body = (array) ($request->getParsedBody() ?? []);
        $note = isset($body['decision_note']) && is_string($body['decision_note']) ? trim($body['decision_note']) : null;
        $ip = $this->resolveClientIp($request);
        $userAgent = $request->getHeaderLine('User-Agent');

        try {
            $item = $this->service->approve($id, $actor, $note, $ip, $userAgent);
            return $this->json($item);
        } catch (NotFoundException $e) {
            return $this->notFound($e->getMessage());
        } catch (ConflictException $e) {
            return $this->problem(409, 'Conflict', $e->getMessage(), 'https://errors.bscms.org/conflict');
        } catch (\BsCms\Operations\Exceptions\ForbiddenException $e) {
            return $this->forbidden($e->getMessage());
        } catch (\BsCms\Operations\Exceptions\ValidationException $e) {
            return $this->problem(422, 'Unprocessable Entity', $e->getMessage(), 'https://errors.bscms.org/validation-error');
        } catch (Throwable $e) {
            return $this->problem(500, 'Internal Server Error', $e->getMessage());
        }
    }

    /**
     * POST /api/v1/pending-changes/{id}/reject
     *
     * @param array<string, string> $args
     */
    public function reject(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }
        $actor = $this->getActor($request);

        if (!$actor->isUser() && !$actor->isSystem()) {
            return $this->forbidden('Only human users may reject pending changes.');
        }

        $id = $args['id'] ?? '';
        $body = (array) ($request->getParsedBody() ?? []);
        $note = isset($body['decision_note']) && is_string($body['decision_note']) ? trim($body['decision_note']) : null;
        $ip = $this->resolveClientIp($request);
        $userAgent = $request->getHeaderLine('User-Agent');

        try {
            $item = $this->service->reject($id, $actor, $note, $ip, $userAgent);
            return $this->json($item);
        } catch (NotFoundException $e) {
            return $this->notFound($e->getMessage());
        } catch (ConflictException $e) {
            return $this->problem(409, 'Conflict', $e->getMessage(), 'https://errors.bscms.org/conflict');
        } catch (\BsCms\Operations\Exceptions\ForbiddenException $e) {
            return $this->forbidden($e->getMessage());
        } catch (\BsCms\Operations\Exceptions\ValidationException $e) {
            return $this->problem(422, 'Unprocessable Entity', $e->getMessage(), 'https://errors.bscms.org/validation-error');
        } catch (Throwable $e) {
            return $this->problem(500, 'Internal Server Error', $e->getMessage());
        }
    }

    /**
     * POST /api/v1/pending-changes/expire
     */
    public function expire(ServerRequestInterface $request): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }
        $actor = $this->getActor($request);

        // Require permission: owner, admin, or authority covering change approvals
        $canExpire = $actor->hasRole(\BsCms\Auth\SystemRoles::ROLE_OWNER)
            || $actor->hasRole(\BsCms\Auth\SystemRoles::ROLE_ADMIN)
            || $this->authorizer->can($actor, 'manage', new Subject('pending_changes', '*'))->allowed
            || $this->authorizer->can($actor, 'publish', new Subject('collection', '*'))->allowed;

        if (!$canExpire) {
            return $this->forbidden('You do not have permission to expire pending changes.');
        }

        $count = $this->service->expireStaleRecords();

        return $this->json([
            'expired_count' => $count,
            'status' => 'ok',
        ]);
    }

    private function forbidden(string $detail): ResponseInterface
    {
        return $this->problem(403, 'Forbidden', $detail);
    }

    private function notFound(string $detail): ResponseInterface
    {
        return $this->problem(404, 'Not Found', $detail);
    }

    private function resolveClientIp(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();
        return (string) ($serverParams['HTTP_X_FORWARDED_FOR'] ?? $serverParams['REMOTE_ADDR'] ?? '127.0.0.1');
    }
}
