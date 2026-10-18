<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Database\Connection;
use BsCms\Schema\CollectionRegistry;
use BsCms\Security\Ulid;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

class SavedViewsController extends BaseController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CollectionRegistry $registry
    ) {
    }

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        $userId = $actor->onBehalfOf ?? $actor->id;

        $params = $request->getQueryParams();
        $collectionKey = (string) ($params['collection'] ?? $params['collection_key'] ?? '');

        $prefix = $this->connection->getPrefix();
        $sql = "SELECT * FROM `{$prefix}saved_views` WHERE `user_id` = ?";
        $bindings = [$userId];

        if ($collectionKey !== '') {
            $sql .= " AND `collection_key` = ?";
            $bindings[] = $collectionKey;
        }

        $sql .= " ORDER BY `position` ASC, `name` ASC";
        $stmt = $this->connection->execute($sql, $bindings);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            if (is_string($row['filters'])) {
                $row['filters'] = json_decode($row['filters'], true) ?? [];
            }
            if (isset($row['columns']) && is_string($row['columns'])) {
                $row['columns'] = json_decode($row['columns'], true) ?? [];
            }
        }
        unset($row);

        return $this->json(array_values($rows));
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        $userId = $actor->onBehalfOf ?? $actor->id;
        $body = $this->getBody($request);

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return $this->problem(422, 'Validation Error', 'View name is required.', null, [
                'name' => ['View name is required.'],
            ]);
        }

        $collectionKey = trim((string) ($body['collection'] ?? $body['collection_key'] ?? ''));
        if ($collectionKey === '') {
            return $this->problem(422, 'Validation Error', 'Collection key is required.', null, [
                'collection' => ['Collection key is required.'],
            ]);
        }

        if (!$this->registry->has($collectionKey)) {
            return $this->problem(422, 'Validation Error', "Collection '{$collectionKey}' does not exist.", null, [
                'collection' => ["Collection '{$collectionKey}' does not exist."],
            ]);
        }

        $sort = isset($body['sort']) ? (string) $body['sort'] : (isset($body['sorts']) ? (is_array($body['sorts']) ? json_encode($body['sorts']) : (string) $body['sorts']) : null);
        $columns = isset($body['columns']) ? (is_array($body['columns']) ? json_encode($body['columns'], JSON_THROW_ON_ERROR) : (string) $body['columns']) : null;
        $filters = json_encode($body['filters'] ?? [], JSON_THROW_ON_ERROR);
        $density = isset($body['density']) ? (string) $body['density'] : null;
        $isShared = !empty($body['is_shared']) ? 1 : 0;
        $position = (int) ($body['position'] ?? 0);

        $prefix = $this->connection->getPrefix();
        $id = Ulid::generate();
        $now = date('Y-m-d H:i:s.v');

        $this->connection->execute(
            "INSERT INTO `{$prefix}saved_views` (
                `id`, `user_id`, `collection_key`, `name`, `filters`,
                `sort`, `columns`, `density`, `is_shared`, `position`, `created_at`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $id,
                $userId,
                $collectionKey,
                $name,
                $filters,
                $sort,
                $columns,
                $density,
                $isShared,
                $position,
                $now,
            ]
        );

        return $this->json($this->fetchView($id, $userId) ?? ['id' => $id], 201);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function get(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        $userId = $actor->onBehalfOf ?? $actor->id;
        $id = (string) ($args['id'] ?? '');

        $view = $this->fetchView($id, $userId);
        if ($view === null) {
            return $this->problem(404, 'Not Found', "Saved view '{$id}' not found.");
        }

        return $this->json($view);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function update(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        $userId = $actor->onBehalfOf ?? $actor->id;
        $id = (string) ($args['id'] ?? '');

        $view = $this->fetchView($id, $userId);
        if ($view === null) {
            return $this->problem(404, 'Not Found', "Saved view '{$id}' not found.");
        }

        $body = $this->getBody($request);
        $prefix = $this->connection->getPrefix();

        $name = isset($body['name']) ? trim((string) $body['name']) : null;
        $collectionKey = isset($body['collection']) || isset($body['collection_key'])
            ? (string) ($body['collection'] ?? $body['collection_key'])
            : null;
        $filters = isset($body['filters']) ? json_encode($body['filters'], JSON_THROW_ON_ERROR) : null;
        $sort = isset($body['sort']) ? (string) $body['sort'] : (isset($body['sorts']) ? (is_array($body['sorts']) ? json_encode($body['sorts']) : (string) $body['sorts']) : null);
        $columns = isset($body['columns']) ? (is_array($body['columns']) ? json_encode($body['columns'], JSON_THROW_ON_ERROR) : (string) $body['columns']) : null;
        $density = isset($body['density']) ? (string) $body['density'] : null;
        $isShared = isset($body['is_shared']) ? (!empty($body['is_shared']) ? 1 : 0) : null;
        $position = isset($body['position']) ? (int) $body['position'] : null;

        $this->connection->execute(
            "UPDATE `{$prefix}saved_views` SET
                `name` = COALESCE(?, `name`),
                `collection_key` = COALESCE(?, `collection_key`),
                `filters` = COALESCE(?, `filters`),
                `sort` = COALESCE(?, `sort`),
                `columns` = COALESCE(?, `columns`),
                `density` = COALESCE(?, `density`),
                `is_shared` = COALESCE(?, `is_shared`),
                `position` = COALESCE(?, `position`)
             WHERE `id` = ? AND `user_id` = ?",
            [
                $name,
                $collectionKey,
                $filters,
                $sort,
                $columns,
                $density,
                $isShared,
                $position,
                $id,
                $userId,
            ]
        );

        return $this->json($this->fetchView($id, $userId) ?? []);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function delete(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        $userId = $actor->onBehalfOf ?? $actor->id;
        $id = (string) ($args['id'] ?? '');

        $view = $this->fetchView($id, $userId);
        if ($view === null) {
            return $this->problem(404, 'Not Found', "Saved view '{$id}' not found.");
        }

        $prefix = $this->connection->getPrefix();
        $this->connection->execute("DELETE FROM `{$prefix}saved_views` WHERE `id` = ? AND `user_id` = ?", [$id, $userId]);

        return $this->json(['success' => true]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchView(string $id, string $userId): ?array
    {
        $prefix = $this->connection->getPrefix();
        $stmt = $this->connection->execute(
            "SELECT * FROM `{$prefix}saved_views` WHERE `id` = ? AND `user_id` = ?",
            [$id, $userId]
        );
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        if (is_string($row['filters'])) {
            $row['filters'] = json_decode($row['filters'], true) ?? [];
        }
        if (isset($row['columns']) && is_string($row['columns'])) {
            $row['columns'] = json_decode($row['columns'], true) ?? [];
        }

        return $row;
    }
}
