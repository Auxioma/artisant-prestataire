<?php

declare(strict_types=1);

namespace App\Messaging\Infrastructure\Persistence;

use App\Messaging\Application\Port\MessageModeration;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class DbalMessageModeration implements MessageModeration
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    /** Nom d'un participant vu par la modération : nom commercial de l'artisan, prénom et nom du client. */
    private const string AUTHOR = "CASE WHEN %1\$s = c.artisan_user_id THEN coalesce(a.trade_name, a.company_name, 'Professionnel') ELSE trim(coalesce(u.first_name, '') || ' ' || coalesce(u.last_name, '')) END";

    public function __construct(private Connection $connection)
    {
    }

    public function pending(int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT m.id, m.conversation_id, m.body, m.original_sealed IS NOT NULL AS masked, m.report_reason, m.created_at, m.reported_at,'
            .' EXISTS (SELECT 1 FROM messaging.attachment x WHERE x.message_id = m.id) AS has_attachment,'
            .' (m.sender_user_id = c.artisan_user_id) AS from_artisan, r.number,'
            .' '.\sprintf(self::AUTHOR, 'm.sender_user_id').' AS sender_name, CASE WHEN m.reported_by IS NULL THEN \'Détection automatique\' ELSE '.\sprintf(self::AUTHOR, 'm.reported_by').' END AS reporter_name'
            .' FROM messaging.message m JOIN messaging.conversation c ON c.id = m.conversation_id'
            .' JOIN artisan.artisan_profile a ON a.id = c.artisan_id JOIN identity.user_account u ON u.id = c.client_user_id'
            .' LEFT JOIN request.quote_request r ON r.id = c.request_id'
            .' WHERE m.reported_at IS NOT NULL AND m.moderated_at IS NULL ORDER BY m.reported_at LIMIT :limit',
            ['limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $r): array => [
            'id' => DbalValue::string($r['id']),
            'conversation_id' => DbalValue::string($r['conversation_id']),
            'sender_name' => DbalValue::string($r['sender_name']),
            'sender_role' => true === $r['from_artisan'] ? 'artisan' : 'client',
            'reporter_name' => DbalValue::string($r['reporter_name']),
            'body' => DbalValue::string($r['body']),
            'masked' => true === $r['masked'],
            'has_attachment' => true === $r['has_attachment'],
            'reason' => DbalValue::string($r['report_reason']),
            'sent_at' => new \DateTimeImmutable(DbalValue::string($r['created_at'])),
            'reported_at' => new \DateTimeImmutable(DbalValue::string($r['reported_at'])),
            'request_number' => DbalValue::nullableString($r['number']),
        ], $rows);
    }

    public function contextOf(string $messageId, int $count): array
    {
        if (1 !== preg_match(self::UUID, $messageId)) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM (SELECT m.id, m.body, m.created_at, '.\sprintf(self::AUTHOR, 'm.sender_user_id').' AS author'
            .' FROM messaging.message m JOIN messaging.message t ON t.id = :id AND t.conversation_id = m.conversation_id'
            .' JOIN messaging.conversation c ON c.id = m.conversation_id JOIN artisan.artisan_profile a ON a.id = c.artisan_id JOIN identity.user_account u ON u.id = c.client_user_id'
            .' WHERE (m.created_at, m.id) <= (t.created_at, t.id) ORDER BY m.created_at DESC, m.id DESC LIMIT :n) x ORDER BY created_at, id',
            ['id' => $messageId, 'n' => $count],
            ['n' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $r): array => [
            'id' => DbalValue::string($r['id']),
            'author' => DbalValue::string($r['author']),
            'body' => DbalValue::string($r['body']),
            'at' => new \DateTimeImmutable(DbalValue::string($r['created_at'])),
        ], $rows);
    }

    public function sealedOriginal(string $messageId): ?string
    {
        if (1 !== preg_match(self::UUID, $messageId)) {
            return null;
        }
        $sealed = $this->connection->fetchOne('SELECT original_sealed FROM messaging.message WHERE id = :id AND reported_at IS NOT NULL', ['id' => $messageId]);

        return \is_string($sealed) ? $sealed : null;
    }

    public function decide(string $messageId, string $adminId, string $decision, ?string $reason, \DateTimeImmutable $at): bool
    {
        if (1 !== preg_match(self::UUID, $messageId)) {
            return false;
        }

        return 1 === (int) $this->connection->executeStatement(
            'UPDATE messaging.message SET moderated_at = :at, moderated_by = :admin, moderation_decision = :d, moderation_reason = :r WHERE id = :id AND reported_at IS NOT NULL AND moderated_at IS NULL',
            ['at' => $at->format(\DATE_ATOM), 'admin' => $adminId, 'd' => $decision, 'r' => $reason, 'id' => $messageId],
        );
    }
}
