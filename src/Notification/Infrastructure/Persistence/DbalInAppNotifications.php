<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Persistence;

use App\Notification\Application\Port\InAppNotifications;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalInAppNotifications implements InAppNotifications
{
    public function __construct(private Connection $connection)
    {
    }

    public function add(string $id, string $userId, string $code, string $body, ?string $link, \DateTimeImmutable $at): void
    {
        $this->connection->insert('notification.in_app', [
            'id' => $id,
            'user_id' => $userId,
            'code' => $code,
            'body' => $body,
            'link' => null !== $link && str_starts_with($link, '/') && !str_starts_with($link, '//') ? $link : null,
            'created_at' => $at->format(\DATE_ATOM),
        ]);
    }

    public function unreadCount(string $userId): int
    {
        return DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM notification.in_app WHERE user_id = :u AND read_at IS NULL', ['u' => $userId]));
    }
}
