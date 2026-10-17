<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Audit\AuditLogger;
use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Users\RoleRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Controller for role and permission management.
 */
class RolesController extends BaseController
{
    public function __construct(
        private readonly RoleRepository $roles,
        private readonly Authorizer $authorizer,
        private readonly AuditLogger $auditLogger
    ) {
    }

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('roles', '*'));
        if ($permError !== null) {
            return $permError;
        }

        $all = $this->roles->list();
        return $this->json(['items' => $all]);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'create', new Subject('roles', '*'));
        if ($permError !== null) {
            return $permError;
        }

        $stepUpError = $this->requireStepUp($request);
        if ($stepUpError !== null) {
            return $stepUpError;
        }

        $body = $this->getBody($request);
        $handle = (string) ($body['handle'] ?? '');
        $name = (string) ($body['name'] ?? '');

        if ($handle === '' || $name === '') {
            return $this->problem(422, 'Unprocessable Content', 'Handle and name are required.');
        }

        /** @var array<int, array<string, mixed>> $permissions */
        $permissions = (array) ($body['permissions'] ?? []);

        try {
            $id = $this->roles->create([
                'handle' => $handle,
                'name' => $name,
                'description' => isset($body['description']) ? (string) $body['description'] : null,
                'is_system' => 0,
                'is_ai' => isset($body['is_ai']) ? (int) $body['is_ai'] : 0,
            ], $permissions);

            $this->auditLogger->record(
                $this->getActor($request),
                'role.created',
                new Subject('role', $id),
                "Created role '{$name}' ({$handle})"
            );

            return $this->json(['status' => 'created', 'id' => $id], 201);
        } catch (\Throwable $e) {
            return $this->problem(422, 'Unprocessable Content', $e->getMessage());
        }
    }

    public function get(ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $request->getAttribute('id', '');
        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('roles', $id));
        if ($permError !== null) {
            return $permError;
        }

        $role = $this->roles->findById($id);
        if ($role === null) {
            return $this->problem(404, 'Not Found', 'Role not found.');
        }

        return $this->json($role);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $request->getAttribute('id', '');
        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('roles', $id));
        if ($permError !== null) {
            return $permError;
        }

        $stepUpError = $this->requireStepUp($request);
        if ($stepUpError !== null) {
            return $stepUpError;
        }

        $body = $this->getBody($request);
        try {
            $this->roles->update($id, $body);

            $this->auditLogger->record(
                $this->getActor($request),
                'role.updated',
                new Subject('role', $id),
                "Updated role '{$id}'",
                $body
            );

            return $this->json(['status' => 'updated']);
        } catch (\Throwable $e) {
            return $this->problem(400, 'Bad Request', $e->getMessage());
        }
    }

    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $request->getAttribute('id', '');
        $permError = $this->requirePermission($this->authorizer, $request, 'delete', new Subject('roles', $id));
        if ($permError !== null) {
            return $permError;
        }

        $stepUpError = $this->requireStepUp($request);
        if ($stepUpError !== null) {
            return $stepUpError;
        }

        try {
            $this->roles->delete($id);

            $this->auditLogger->record(
                $this->getActor($request),
                'role.deleted',
                new Subject('role', $id),
                "Deleted role '{$id}'"
            );

            return $this->json(['status' => 'deleted']);
        } catch (\Throwable $e) {
            return $this->problem(400, 'Bad Request', $e->getMessage());
        }
    }

    public function updatePermissions(ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $request->getAttribute('id', '');
        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('roles', $id));
        if ($permError !== null) {
            return $permError;
        }

        $stepUpError = $this->requireStepUp($request);
        if ($stepUpError !== null) {
            return $stepUpError;
        }

        $body = $this->getBody($request);
        /** @var array<int, array<string, mixed>> $permissions */
        $permissions = (array) ($body['permissions'] ?? $body);

        try {
            $this->roles->setPermissions($id, $permissions);

            $this->auditLogger->record(
                $this->getActor($request),
                'role.permissions_updated',
                new Subject('role', $id),
                "Updated permissions for role '{$id}'"
            );

            return $this->json(['status' => 'permissions_updated']);
        } catch (\Throwable $e) {
            return $this->problem(400, 'Bad Request', $e->getMessage());
        }
    }

    public function clone(ServerRequestInterface $request): ResponseInterface
    {
        $sourceId = (string) $request->getAttribute('id', '');
        $permError = $this->requirePermission($this->authorizer, $request, 'create', new Subject('roles', '*'));
        if ($permError !== null) {
            return $permError;
        }

        $stepUpError = $this->requireStepUp($request);
        if ($stepUpError !== null) {
            return $stepUpError;
        }

        $body = $this->getBody($request);
        $handle = (string) ($body['handle'] ?? '');
        $name = (string) ($body['name'] ?? '');
        $description = isset($body['description']) ? (string) $body['description'] : null;

        if ($handle === '' || $name === '') {
            return $this->problem(422, 'Unprocessable Content', 'Handle and name are required.');
        }

        try {
            $newId = $this->roles->clone($sourceId, $handle, $name, $description);

            $this->auditLogger->record(
                $this->getActor($request),
                'role.cloned',
                new Subject('role', $newId),
                "Cloned role '{$sourceId}' to '{$newId}' ({$handle})"
            );

            return $this->json(['status' => 'cloned', 'id' => $newId], 201);
        } catch (\Throwable $e) {
            return $this->problem(400, 'Bad Request', $e->getMessage());
        }
    }
}
