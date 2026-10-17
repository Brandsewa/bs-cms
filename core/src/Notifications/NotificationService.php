<?php

declare(strict_types=1);

namespace BsCms\Notifications;

use BsCms\Database\Connection;
use BsCms\Security\Ulid;
use BsCms\Support\Clock;
use PDO;

/**
 * Service for managing user notifications stored in bs_notifications.
 */
final class NotificationService
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Create and store a notification for a user.
     *
     * @return string Generated ULID of the notification
     */
    public function notify(
        string $userId,
        string $type,
        string $title,
        ?string $body = null,
        ?string $link = null
    ): string {
        $id = Ulid::generate();
        $now = Clock::sql(Clock::now());

        $notifTable = $this->connection->prefixTable('notifications');
        $stmt = $this->connection->getPdo()->prepare(
            "INSERT INTO `{$notifTable}` (`id`, `user_id`, `type`, `title`, `body`, `link`, `read_at`, `created_at`)
             VALUES (?, ?, ?, ?, ?, ?, NULL, ?)"
        );

        $stmt->execute([
            $id,
            $userId,
            substr($type, 0, 48),
            substr($title, 0, 190),
            $body !== null ? substr($body, 0, 500) : null,
            $link !== null ? substr($link, 0, 512) : null,
            $now,
        ]);

        return $id;
    }

    /**
     * Get paginated notifications for a user.
     *
     * @return array{items: array<int, array<string, mixed>>, unread_count: int, next_cursor: ?string}
     */
    public function getForUser(
        string $userId,
        bool $unreadOnly = false,
        int $limit = 50,
        ?string $cursor = null
    ): array {
        $notifTable = $this->connection->prefixTable('notifications');
        $pdo = $this->connection->getPdo();

        $where = ['`user_id` = ?'];
        $params = [$userId];

        if ($unreadOnly) {
            $where[] = '`read_at` IS NULL';
        }

        if ($cursor !== null) {
            $where[] = '`id` < ?';
            $params[] = $cursor;
        }

        $whereSql = implode(' AND ', $where);
        $safeLimit = max(1, min($limit, 100));

        // Fetch items plus 1 to determine has_more
        $fetchLimit = $safeLimit + 1;
        $sql = "SELECT * FROM `{$notifTable}`
                WHERE {$whereSql}
                ORDER BY `id` DESC
                LIMIT {$fetchLimit}";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $nextCursor = null;
        if (count($rows) > $safeLimit) {
            $extra = array_pop($rows);
            $last = end($rows);
            $nextCursor = $last !== false ? (string) $last['id'] : null;
        }

        $unreadCount = $this->unreadCount($userId);

        return [
            'items' => $rows,
            'unread_count' => $unreadCount,
            'next_cursor' => $nextCursor,
        ];
    }

    /**
     * Mark specific notifications as read for a user.
     *
     * @param array<int, string> $notificationIds
     * @return int Number of rows updated
     */
    public function markAsRead(string $userId, array $notificationIds): int
    {
        if (empty($notificationIds)) {
            return 0;
        }

        $notifTable = $this->connection->prefixTable('notifications');
        $now = Clock::sql(Clock::now());

        $placeholders = implode(',', array_fill(0, count($notificationIds), '?'));
        $sql = "UPDATE `{$notifTable}`
                SET `read_at` = ?
                WHERE `user_id` = ? AND `id` IN ({$placeholders}) AND `read_at` IS NULL";

        $params = array_merge([$now, $userId], array_values($notificationIds));
        $stmt = $this->connection->getPdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * Mark all notifications as read for a user.
     */
    public function markAllAsRead(string $userId): int
    {
        $notifTable = $this->connection->prefixTable('notifications');
        $now = Clock::sql(Clock::now());

        $sql = "UPDATE `{$notifTable}`
                SET `read_at` = ?
                WHERE `user_id` = ? AND `read_at` IS NULL";

        $stmt = $this->connection->getPdo()->prepare($sql);
        $stmt->execute([$now, $userId]);

        return $stmt->rowCount();
    }

    /**
     * Get count of unread notifications for a user.
     */
    public function unreadCount(string $userId): int
    {
        $notifTable = $this->connection->prefixTable('notifications');
        $sql = "SELECT COUNT(*) FROM `{$notifTable}` WHERE `user_id` = ? AND `read_at` IS NULL";

        $stmt = $this->connection->getPdo()->prepare($sql);
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }
}
