<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Audit\AuditLogger;
use BsCms\Auth\InvitationService;
use BsCms\Auth\SessionManager;
use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Users\RoleRepository;
use BsCms\Users\UserPresenter;
use BsCms\Users\UserRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Controller for user management: listing, inviting, updating roles,
 * suspending, unlocking, deleting, bulk actions, and session management.
 * Enforces strict last-active-owner protection.
 */
class UsersController extends BaseController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly InvitationService $invitationService,
        private readonly SessionManager $sessions,
        private readonly Authorizer $authorizer,
        private readonly AuditLogger $auditLogger
    ) {
    }

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('users', '*'));
        if ($permError !== null) {
            return $permError;
        }

        $params = $request->getQueryParams();
        $filters = [];
        if (isset($params['status']) && is_string($params['status'])) {
            $filters['status'] = $params['status'];
        }
        if (isset($params['search']) && is_string($params['search'])) {
            $filters['search'] = $params['search'];
        }

        $sort = isset($params['sort']) && is_string($params['sort']) ? $params['sort'] : 'created_at';
        $direction = isset($params['direction']) && is_string($params['direction']) ? $params['direction'] : 'DESC';
        $limit = isset($params['limit']) ? (int) $params['limit'] : 50;
        $cursor = isset($params['cursor']) && is_string($params['cursor']) ? $params['cursor'] : null;

        $result = $this->users->list($filters, $sort, $direction, $limit, $cursor);
        $result['items'] = UserPresenter::presentCollection($result['items']);

        return $this->json($result);
    }

    public function invite(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'create', new Subject('users', '*'));
        if ($permError !== null) {
            return $permError;
        }

        $actor = $this->getActor($request);
        $body = $this->getBody($request);
        $email = (string) ($body['email'] ?? '');
        $roleId = (string) ($body['role_id'] ?? '');

        if ($email === '' || $roleId === '') {
            return $this->problem(422, 'Unprocessable Content', 'Email and role_id are required.');
        }

        try {
            $token = $this->invitationService->invite($email, $roleId, $actor->id);
            return $this->json(['status' => 'invited', 'token' => $token], 201);
        } catch (\Throwable $e) {
            return $this->problem(422, 'Unprocessable Content', $e->getMessage());
        }
    }

    public function get(ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $request->getAttribute('id', '');
        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('users', $id));
        if ($permError !== null) {
            return $permError;
        }

        $user = $this->users->findById($id);
        if ($user === null) {
            return $this->problem(404, 'Not Found', 'User not found.');
        }

        return $this->json(UserPresenter::present($user));
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $request->getAttribute('id', '');
        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('users', $id));
        if ($permError !== null) {
            return $permError;
        }

        $actor = $this->getActor($request);
        $user = $this->users->findById($id);
        if ($user === null) {
            return $this->problem(404, 'Not Found', 'User not found.');
        }

        $body = $this->getBody($request);
        $updates = [];

        if (isset($body['name']) && is_string($body['name'])) {
            $updates['name'] = $body['name'];
        }

        if (isset($body['status']) && is_string($body['status'])) {
            if ($body['status'] !== 'active' && $this->isOwnerUser($user) && $this->users->countActiveOwners() <= 1) {
                return $this->problem(400, 'Bad Request', 'The last active owner cannot be suspended or deactivated.');
            }
            $updates['status'] = $body['status'];
        }

        if (isset($body['roles']) && is_array($body['roles'])) {
            /** @var array<int, string> $roleIds */
            $roleIds = $body['roles'];
            $ownerRole = $this->roles->findByHandle('owner');

            // If removing owner role from last owner
            if ($ownerRole !== null && $this->isOwnerUser($user) && !in_array($ownerRole['id'], $roleIds, true)) {
                if ($this->users->countActiveOwners() <= 1) {
                    return $this->problem(400, 'Bad Request', 'The last active owner cannot be downgraded.');
                }
            }

            $this->users->setRoles($id, $roleIds);
        }

        if (!empty($updates)) {
            $this->users->update($id, $updates);
        }

        $serverParams = $request->getServerParams();
        $ip = isset($serverParams['REMOTE_ADDR']) && is_string($serverParams['REMOTE_ADDR']) ? $serverParams['REMOTE_ADDR'] : null;
        $userAgent = $request->getHeaderLine('User-Agent') ?: null;
        $requestId = (string) ($request->getAttribute('request_id') ?? '');

        $this->auditLogger->record(
            actor: $actor,
            action: 'user.updated',
            subject: new Subject('user', $id),
            summary: "Updated user {$user['email']}",
            diff: $updates,
            requestId: $requestId,
            ip: $ip,
            userAgent: $userAgent,
            subjectId: $id
        );

        return $this->json(['status' => 'user_updated']);
    }

    public function suspend(ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $request->getAttribute('id', '');
        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('users', $id));
        if ($permError !== null) {
            return $permError;
        }

        $user = $this->users->findById($id);
        if ($user === null) {
            return $this->problem(404, 'Not Found', 'User not found.');
        }

        if ($this->isOwnerUser($user) && $this->users->countActiveOwners() <= 1) {
            return $this->problem(400, 'Bad Request', 'The last active owner cannot be suspended.');
        }

        $this->users->suspend($id);
        $this->sessions->revokeAllForUser($id);

        $serverParams = $request->getServerParams();
        $ip = isset($serverParams['REMOTE_ADDR']) && is_string($serverParams['REMOTE_ADDR']) ? $serverParams['REMOTE_ADDR'] : null;
        $userAgent = $request->getHeaderLine('User-Agent') ?: null;
        $requestId = (string) ($request->getAttribute('request_id') ?? '');

        $this->auditLogger->record(
            actor: $this->getActor($request),
            action: 'user.suspended',
            subject: new Subject('user', $id),
            summary: "Suspended user {$user['email']}",
            requestId: $requestId,
            ip: $ip,
            userAgent: $userAgent,
            subjectId: $id
        );

        return $this->json(['status' => 'user_suspended']);
    }

    public function unsuspend(ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $request->getAttribute('id', '');
        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('users', $id));
        if ($permError !== null) {
            return $permError;
        }

        $this->users->unsuspend($id);

        return $this->json(['status' => 'user_unsuspended']);
    }

    public function unlock(ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $request->getAttribute('id', '');
        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('users', $id));
        if ($permError !== null) {
            return $permError;
        }

        $this->users->unlock($id);

        return $this->json(['status' => 'user_unlocked']);
    }

    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $request->getAttribute('id', '');
        $permError = $this->requirePermission($this->authorizer, $request, 'delete', new Subject('users', $id));
        if ($permError !== null) {
            return $permError;
        }

        $stepUpError = $this->requireStepUp($request);
        if ($stepUpError !== null) {
            return $stepUpError;
        }

        $user = $this->users->findById($id);
        if ($user === null) {
            return $this->problem(404, 'Not Found', 'User not found.');
        }

        if ($this->isOwnerUser($user) && $this->users->countActiveOwners() <= 1) {
            return $this->problem(400, 'Bad Request', 'The last active owner cannot be deleted.');
        }

        $this->users->delete($id);
        $this->sessions->revokeAllForUser($id);

        $serverParams = $request->getServerParams();
        $ip = isset($serverParams['REMOTE_ADDR']) && is_string($serverParams['REMOTE_ADDR']) ? $serverParams['REMOTE_ADDR'] : null;
        $userAgent = $request->getHeaderLine('User-Agent') ?: null;
        $requestId = (string) ($request->getAttribute('request_id') ?? '');

        $this->auditLogger->record(
            actor: $this->getActor($request),
            action: 'user.deleted',
            subject: new Subject('user', $id),
            summary: "Deleted user {$user['email']}",
            requestId: $requestId,
            ip: $ip,
            userAgent: $userAgent,
            subjectId: $id
        );

        return $this->json(['status' => 'user_deleted']);
    }

    public function bulk(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('users', '*'));
        if ($permError !== null) {
            return $permError;
        }

        $body = $this->getBody($request);
        $action = (string) ($body['action'] ?? '');
        /** @var array<int, string> $userIds */
        $userIds = (array) ($body['user_ids'] ?? []);

        if (empty($userIds) || $action === '') {
            return $this->problem(422, 'Unprocessable Content', 'Action and user_ids are required.');
        }

        $affected = 0;
        foreach ($userIds as $userId) {
            $user = $this->users->findById($userId);
            if ($user === null) {
                continue;
            }

            if ($action === 'suspend') {
                if ($this->isOwnerUser($user) && $this->users->countActiveOwners() <= 1) {
                    continue;
                }
                $this->users->suspend($userId);
                $this->sessions->revokeAllForUser($userId);
                ++$affected;
            } elseif ($action === 'delete') {
                if ($this->isOwnerUser($user) && $this->users->countActiveOwners() <= 1) {
                    continue;
                }
                $this->users->delete($userId);
                $this->sessions->revokeAllForUser($userId);
                ++$affected;
            } elseif ($action === 'change_role' && isset($body['role_id'])) {
                $roleId = (string) $body['role_id'];
                $ownerRole = $this->roles->findByHandle('owner');
                if ($ownerRole !== null && $this->isOwnerUser($user) && $roleId !== $ownerRole['id'] && $this->users->countActiveOwners() <= 1) {
                    continue;
                }
                $this->users->setRoles($userId, [$roleId]);
                ++$affected;
            }
        }

        return $this->json(['status' => 'bulk_action_completed', 'affected' => $affected]);
    }

    public function getSessions(ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $request->getAttribute('id', '');
        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('users', $id));
        if ($permError !== null) {
            return $permError;
        }

        $sessions = $this->sessions->listActiveForUser($id);
        return $this->json(['items' => $sessions]);
    }

    public function deleteSessions(ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $request->getAttribute('id', '');
        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('users', $id));
        if ($permError !== null) {
            return $permError;
        }

        $this->sessions->revokeAllForUser($id);
        return $this->json(['status' => 'user_sessions_revoked']);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function isOwnerUser(array $user): bool
    {
        /** @var array<int, array<string, mixed>> $roles */
        $roles = $user['roles'] ?? [];
        foreach ($roles as $r) {
            if (($r['handle'] ?? '') === 'owner') {
                return true;
            }
        }
        return false;
    }
}
