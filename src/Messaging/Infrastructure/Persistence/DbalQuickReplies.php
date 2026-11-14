<?php

declare(strict_types=1);

namespace App\Messaging\Infrastructure\Persistence;

use App\Messaging\Application\Port\QuickReplies;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalQuickReplies implements QuickReplies
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private Connection $connection)
    {
    }

    public function of(string $userId): array
    {
        if (1 !== preg_match(self::UUID, $userId)) {
            return [];
        }

        return array_map(static fn (array $r): array => ['id' => DbalValue::string($r['id']), 'title' => DbalValue::string($r['title']), 'body' => DbalValue::string($r['body'])], $this->connection->fetchAllAssociative(
            'SELECT id, title, body FROM messaging.quick_reply WHERE user_id = :u ORDER BY created_at, id',
            ['u' => $userId],
        ));
    }

    public function add(string $id, string $userId, string $title, string $body, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            'INSERT INTO messaging.quick_reply (id, user_id, title, body, created_at) VALUES (:id, :u, :t, :b, :at) ON CONFLICT (id) DO NOTHING',
            ['id' => $id, 'u' => $userId, 't' => $title, 'b' => $body, 'at' => $at->format(\DATE_ATOM)],
        );
    }

    public function remove(string $id, string $userId): bool
    {
        if (1 !== preg_match(self::UUID, $id)) {
            return false;
        }

        return 1 === (int) $this->connection->executeStatement('DELETE FROM messaging.quick_reply WHERE id = :id AND user_id = :u', ['id' => $id, 'u' => $userId]);
    }
}
