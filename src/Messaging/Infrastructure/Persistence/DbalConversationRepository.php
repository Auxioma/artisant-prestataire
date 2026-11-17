<?php

declare(strict_types=1);

namespace App\Messaging\Infrastructure\Persistence;

use App\Messaging\Domain\Conversation\Conversation;
use App\Messaging\Domain\Conversation\ConversationId;
use App\Messaging\Domain\Conversation\ConversationKind;
use App\Messaging\Domain\Conversation\ConversationRepository;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalConversationRepository implements ConversationRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function find(ConversationId $id): ?Conversation
    {
        return $this->one('SELECT * FROM messaging.conversation WHERE id = :id', ['id' => $id->value]);
    }

    public function findForUpdate(ConversationId $id): ?Conversation
    {
        return $this->one('SELECT * FROM messaging.conversation WHERE id = :id FOR UPDATE', ['id' => $id->value]);
    }

    public function findForRequest(string $requestId, string $artisanId): ?Conversation
    {
        return $this->one('SELECT * FROM messaging.conversation WHERE request_id = :r AND artisan_id = :a FOR UPDATE', ['r' => $requestId, 'a' => $artisanId]);
    }

    public function findPreQuestion(string $clientUserId, string $artisanId): ?Conversation
    {
        return $this->one("SELECT * FROM messaging.conversation WHERE kind = 'pre_question' AND client_user_id = :c AND artisan_id = :a FOR UPDATE", ['c' => $clientUserId, 'a' => $artisanId]);
    }

    public function attachmentsInactiveBefore(\DateTimeImmutable $before): array
    {
        return array_map(static fn (mixed $id): string => DbalValue::string($id), $this->connection->fetchFirstColumn(
            'SELECT a.file_id FROM messaging.attachment a JOIN messaging.conversation c ON c.id = a.conversation_id WHERE coalesce(c.last_message_at, c.created_at) < :before',
            ['before' => $before->format(\DATE_ATOM)],
        ));
    }

    public function purgeInactiveBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->connection->executeStatement('DELETE FROM messaging.conversation WHERE coalesce(last_message_at, created_at) < :before', ['before' => $before->format(\DATE_ATOM)]);
    }

    public function save(Conversation $conversation): void
    {
        $at = $conversation->lastMessageAt()?->format(\DATE_ATOM);
        $updated = (int) $this->connection->executeStatement(
            'UPDATE messaging.conversation SET blocked_by = :blocked, last_message_at = :at WHERE id = :id',
            ['blocked' => $conversation->blockedBy(), 'at' => $at, 'id' => $conversation->id()->value],
        );
        if (0 === $updated) {
            $this->connection->insert('messaging.conversation', [
                'id' => $conversation->id()->value,
                'kind' => $conversation->kind()->value,
                'request_id' => $conversation->requestId(),
                'client_user_id' => $conversation->clientUserId(),
                'artisan_id' => $conversation->artisanId(),
                'artisan_user_id' => $conversation->artisanUserId(),
                'started_by' => $conversation->startedByUserId(),
                'blocked_by' => $conversation->blockedBy(),
                'last_message_at' => $at,
                'created_at' => $conversation->createdAt()->format(\DATE_ATOM),
            ]);
        }
        foreach ($conversation->releasePosted() as $message) {
            $this->connection->insert('messaging.message', [
                'id' => $message->id,
                'conversation_id' => $conversation->id()->value,
                'sender_user_id' => $message->senderUserId,
                'body' => $message->body,
                'original_sealed' => $message->sealedOriginal,
                'created_at' => $message->at->format(\DATE_ATOM),
            ]);
            if (null !== $message->attachmentFileId) {
                $this->connection->insert('messaging.attachment', [
                    'file_id' => $message->attachmentFileId,
                    'message_id' => $message->id,
                    'conversation_id' => $conversation->id()->value,
                    'original_name' => $message->attachmentName,
                    'created_at' => $message->at->format(\DATE_ATOM),
                ]);
            }
        }
    }

    /** @param array<string, string> $params */
    private function one(string $sql, array $params): ?Conversation
    {
        $r = $this->connection->fetchAssociative($sql, $params);
        if (false === $r) {
            return null;
        }

        return Conversation::restore(
            new ConversationId(DbalValue::string($r['id'])),
            ConversationKind::from(DbalValue::string($r['kind'])),
            DbalValue::nullableString($r['request_id']),
            DbalValue::string($r['client_user_id']),
            DbalValue::string($r['artisan_id']),
            DbalValue::string($r['artisan_user_id']),
            DbalValue::string($r['started_by']),
            DbalValue::nullableString($r['blocked_by']),
            null === $r['last_message_at'] ? null : new \DateTimeImmutable(DbalValue::string($r['last_message_at'])),
            new \DateTimeImmutable(DbalValue::string($r['created_at'])),
        );
    }
}
