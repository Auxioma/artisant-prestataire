<?php

declare(strict_types=1);

namespace App\Messaging\Infrastructure\Persistence;

use App\Messaging\Application\Port\ParticipantStates;
use Doctrine\DBAL\Connection;

final readonly class DbalParticipantStates implements ParticipantStates
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private Connection $connection)
    {
    }

    public function ensure(string $conversationId, string $userId): void
    {
        $this->connection->executeStatement('INSERT INTO messaging.participant (conversation_id, user_id) VALUES (:c, :u) ON CONFLICT DO NOTHING', ['c' => $conversationId, 'u' => $userId]);
    }

    public function markRead(string $conversationId, string $userId, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            'INSERT INTO messaging.participant (conversation_id, user_id, last_read_at) VALUES (:c, :u, :at) ON CONFLICT (conversation_id, user_id) DO UPDATE SET last_read_at = GREATEST(coalesce(messaging.participant.last_read_at, excluded.last_read_at), excluded.last_read_at)',
            ['c' => $conversationId, 'u' => $userId, 'at' => $at->format(\DATE_ATOM)],
        );
    }

    public function archive(string $conversationId, string $userId, bool $archived): void
    {
        $this->connection->executeStatement('UPDATE messaging.participant SET archived = :a WHERE conversation_id = :c AND user_id = :u', ['a' => $archived ? 'true' : 'false', 'c' => $conversationId, 'u' => $userId]);
    }

    public function claimNotification(string $conversationId, string $userId, \DateTimeImmutable $messageAt, \DateTimeImmutable $notSince, \DateTimeImmutable $at): bool
    {
        return 1 === (int) $this->connection->executeStatement(
            <<<'SQL'
                UPDATE messaging.participant SET last_notified_at = :at
                WHERE conversation_id = :c AND user_id = :u
                  AND (last_notified_at IS NULL OR last_notified_at < :since)
                  AND (last_read_at IS NULL OR last_read_at < :message_at)
                SQL,
            ['at' => $at->format(\DATE_ATOM), 'c' => $conversationId, 'u' => $userId, 'since' => $notSince->format(\DATE_ATOM), 'message_at' => $messageAt->format(\DATE_ATOM)],
        );
    }

    public function saveNote(string $conversationId, string $userId, ?string $note): bool
    {
        if (1 !== preg_match(self::UUID, $conversationId)) {
            return false;
        }

        return 1 === (int) $this->connection->executeStatement('UPDATE messaging.participant SET note = :n WHERE conversation_id = :c AND user_id = :u', ['n' => $note, 'c' => $conversationId, 'u' => $userId]);
    }

    public function report(string $messageId, string $userId, string $reason, \DateTimeImmutable $at): bool
    {
        if (1 !== preg_match(self::UUID, $messageId)) {
            return false;
        }

        return 1 === (int) $this->connection->executeStatement(
            'UPDATE messaging.message SET reported_by = :u, reported_at = :at, report_reason = :r WHERE id = :id AND sender_user_id <> :u AND reported_at IS NULL',
            ['u' => $userId, 'at' => $at->format(\DATE_ATOM), 'r' => $reason, 'id' => $messageId],
        );
    }

    public function flag(string $messageId, string $reason, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            'UPDATE messaging.message SET reported_at = :at, report_reason = :r WHERE id = :id AND reported_at IS NULL',
            ['at' => $at->format(\DATE_ATOM), 'r' => $reason, 'id' => $messageId],
        );
    }
}
