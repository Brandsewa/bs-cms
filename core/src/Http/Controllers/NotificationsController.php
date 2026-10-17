<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Notifications\NotificationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Controller for viewing and dismissing in-app notifications.
 */
class NotificationsController extends BaseController
{
    public function __construct(
        private readonly NotificationService $notifications
    ) {
    }

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        $params = $request->getQueryParams();

        $unreadOnly = isset($params['unread']) && in_array(strtolower((string) $params['unread']), ['1', 'true', 'yes'], true);
        $limit = isset($params['limit']) ? min(100, max(1, (int) $params['limit'])) : 50;
        $cursor = isset($params['cursor']) && is_string($params['cursor']) ? $params['cursor'] : null;

        $result = $this->notifications->getForUser($actor->id, $unreadOnly, $limit, $cursor);

        return $this->json($result);
    }

    public function markRead(ServerRequestInterface $request): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        $body = $this->getBody($request);
        /** @var array<int, string> $ids */
        $ids = (array) ($body['ids'] ?? []);

        if (empty($ids)) {
            return $this->problem(422, 'Unprocessable Content', 'List of notification ids is required.');
        }

        $affected = $this->notifications->markAsRead($actor->id, $ids);

        return $this->json(['status' => 'marked_read', 'affected' => $affected]);
    }

    public function markAllRead(ServerRequestInterface $request): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        $affected = $this->notifications->markAllAsRead($actor->id);

        return $this->json(['status' => 'all_marked_read', 'affected' => $affected]);
    }
}
