<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Persistence;

use App\Notification\Application\Preference\NotificationPreferences;
use App\Notification\Application\Preference\NotificationTopic;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalNotificationPreferences implements NotificationPreferences
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private Connection $connection)
    {
    }

    public function enabled(string $userId, NotificationTopic $topic, string $channel): bool
    {
        return $this->forChannel($userId, $channel)[$topic->value] ?? true;
    }

    public function forChannel(string $userId, string $channel): array
    {
        if (1 !== preg_match(self::UUID, $userId)) {
            return [];
        }
        $map = [];
        foreach ($this->connection->fetchAllAssociative('SELECT topic, enabled FROM notification.preference WHERE user_id = :u AND channel = :c', ['u' => $userId, 'c' => $channel]) as $row) {
            $map[DbalValue::string($row['topic'])] = (bool) $row['enabled'];
        }

        return $map;
    }

    public function save(string $userId, NotificationTopic $topic, string $channel, bool $enabled, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            'INSERT INTO notification.preference (user_id, topic, channel, enabled, updated_at) VALUES (:u, :t, :c, :e, :at)
             ON CONFLICT (user_id, topic, channel) DO UPDATE SET enabled = EXCLUDED.enabled, updated_at = EXCLUDED.updated_at',
            ['u' => $userId, 't' => $topic->value, 'c' => $channel, 'e' => $enabled ? 'true' : 'false', 'at' => $at->format(\DATE_ATOM)],
        );
    }

    public function forget(string $userId): void
    {
        $this->connection->executeStatement('DELETE FROM notification.preference WHERE user_id = :u', ['u' => $userId]);
    }
}
