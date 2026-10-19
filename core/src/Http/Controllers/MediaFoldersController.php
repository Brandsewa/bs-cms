<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Media\MediaFolderRepository;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

class MediaFoldersController extends BaseController
{
    public function __construct(
        private readonly MediaFolderRepository $folders,
        private readonly Authorizer $authorizer
    ) {
    }

    /**
     * GET /api/v1/media/folders
     */
    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('media', '*'));
        if ($permError !== null) {
            return $permError;
        }

        $params = $request->getQueryParams();
        $parentId = isset($params['parent_id']) && (string) $params['parent_id'] !== '' ? (string) $params['parent_id'] : null;

        $items = $this->folders->list($parentId);
        return $this->json($items);
    }

    /**
     * POST /api/v1/media/folders
     */
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'create', new Subject('media', '*'));
        if ($permError !== null) {
            return $permError;
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $name = isset($body['name']) ? trim((string) $body['name']) : '';
        if ($name === '') {
            return $this->problem(422, 'Validation Failed', 'Folder name is required.', null, [
                'name' => ['Folder name cannot be empty.']
            ]);
        }

        $parentId = isset($body['parent_id']) && (string) $body['parent_id'] !== '' ? (string) $body['parent_id'] : null;

        try {
            $folder = $this->folders->create($name, $parentId);
            return $this->json($folder, 201);
        } catch (InvalidArgumentException $e) {
            return $this->problem(422, 'Validation Failed', $e->getMessage());
        } catch (Throwable $e) {
            $this->logServerError($request, $e, 'media_folder_create');
            return $this->problem(500, 'Internal Server Error', 'Failed to create folder.');
        }
    }

    /**
     * PATCH /api/v1/media/folders/{id}
     *
     * @param array<string, mixed> $args
     */
    public function update(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('media', '*'));
        if ($permError !== null) {
            return $permError;
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        try {
            $updated = $this->folders->update($id, $body);
            return $this->json($updated);
        } catch (InvalidArgumentException $e) {
            return $this->problem(422, 'Validation Failed', $e->getMessage());
        } catch (Throwable $e) {
            $this->logServerError($request, $e, 'media_folder_update');
            return $this->problem(500, 'Internal Server Error', 'Failed to update folder.');
        }
    }

    /**
     * DELETE /api/v1/media/folders/{id}
     *
     * @param array<string, mixed> $args
     */
    public function delete(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $permError = $this->requirePermission($this->authorizer, $request, 'delete', new Subject('media', '*'));
        if ($permError !== null) {
            return $permError;
        }

        try {
            $this->folders->delete($id);
            return $this->json(['message' => "Folder '{$id}' deleted successfully."]);
        } catch (InvalidArgumentException $e) {
            return $this->problem(409, 'Conflict', $e->getMessage());
        } catch (Throwable $e) {
            $this->logServerError($request, $e, 'media_folder_delete');
            return $this->problem(500, 'Internal Server Error', 'Failed to delete folder.');
        }
    }

    /**
     * POST /api/v1/media/folders/{id}/move
     *
     * @param array<string, mixed> $args
     */
    public function move(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('media', '*'));
        if ($permError !== null) {
            return $permError;
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $newParentId = array_key_exists('parent_id', $body) && $body['parent_id'] !== '' && $body['parent_id'] !== null
            ? (string) $body['parent_id']
            : null;

        try {
            $updated = $this->folders->move($id, $newParentId);
            return $this->json($updated);
        } catch (InvalidArgumentException $e) {
            return $this->problem(422, 'Validation Failed', $e->getMessage());
        } catch (Throwable $e) {
            $this->logServerError($request, $e, 'media_folder_move');
            return $this->problem(500, 'Internal Server Error', 'Failed to move folder.');
        }
    }
}
