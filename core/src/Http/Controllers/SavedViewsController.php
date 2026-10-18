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
        $collectionHandle = (string) ($params['collection'] ?? '');

        $prefix = $this->connection->getPrefix();
        $sql = "SELECT * FROM `{$prefix}saved_views` WHERE `user_id` = ?";
        $bindings = [$userId];

        if ($collectionHandle !== '') {
            if ($this->registry->has($collectionHandle)) {
                $col = $this->registry->get($collectionHandle);
                $sql .= " AND `collection_id` = ?";
                $bindings[] = (string) $col['id'];
            }
        }

        $sql .= " ORDER BY `position` ASC, `name` ASC";
        $stmt = $this->connection->execute($sql, $bindings);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            if (is_string($row['filters'])) {
                $row['filters'] = json_decode($row['filters'], true) ?? [];
            }
            if (is_string($row['sorts'])) {
                $row['sorts'] = json_decode($row['sorts'], true) ?? [];
            }
            if (is_string($row['columns'])) {
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

        $colHandle = (string) ($body['collection'] ?? '');
        $colId = null;
        if ($colHandle !== '' && $this->registry->has($colHandle)) {
            $colId = (string) $this->registry->get($colHandle)['id'];
        } elseif (!empty($body['collection_id'])) {
            $colId = (string) $body['collection_id'];
        }

        $prefix = $this->connection->getPrefix();
        $id = Ulid::generate();
        $now = date('Y-m-d H:i:s.v');

        $this->connection->execute(
            "INSERT INTO `{$prefix}saved_views` (
                `id`, `user_id`, `collection_id`, `name`, `icon`, `filters`,
                `sorts`, `columns`, `per_page`, `is_pinned`, `position`, `created_at`, `updated_at`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $id,
                $userId,
                $colId,
                $name,
                $body['icon'] ?? null,
                json_encode($body['filters'] ?? [], JSON_THROW_ON_ERROR),
                json_encode($body['sorts'] ?? [], JSON_THROW_ON_ERROR),
                json_encode($body['columns'] ?? [], JSON_THROW_ON_ERROR),
                (int) ($body['per_page'] ?? 25),
                !empty($body['is_pinned']) ? 1 : 0,
                (int) ($body['position'] ?? 0),
                $now,
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
        $now = date('Y-m-d H:i:s.v');

        $this->connection->execute(
            "UPDATE `{$prefix}saved_views` SET
                `name` = COALESCE(?, `name`),
                `icon` = COALESCE(?, `icon`),
                `filters` = COALESCE(?, `filters`),
                `sorts` = COALESCE(?, `sorts`),
                `columns` = COALESCE(?, `columns`),
                `per_page` = COALESCE(?, `per_page`),
                `is_pinned` = COALESCE(?, `is_pinned`),
                `position` = COALESCE(?, `position`),
                `updated_at` = ?
             WHERE `id` = ? AND `user_id` = ?",
            [
                $body['name'] ?? null,
                $body['icon'] ?? null,
                isset($body['filters']) ? json_encode($body['filters'], JSON_THROW_ON_ERROR) : null,
                isset($body['sorts']) ? json_encode($body['sorts'], JSON_THROW_ON_ERROR) : null,
                isset($body['columns']) ? json_encode($body['columns'], JSON_THROW_ON_ERROR) : null,
                $body['per_page'] ?? null,
                isset($body['is_pinned']) ? (!empty($body['is_pinned']) ? 1 : 0) : null,
                $body['position'] ?? null,
                $now,
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
        if (is_string($row['sorts'])) {
            $row['sorts'] = json_decode($row['sorts'], true) ?? [];
        }
        if (is_string($row['columns'])) {
            $row['columns'] = json_decode($row['columns'], true) ?? [];
        }

        return $row;
    }
}
