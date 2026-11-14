<?php

declare(strict_types=1);

namespace App\Messaging\Infrastructure\Persistence;

use App\Messaging\Application\Query\ConversationReader;
use App\Messaging\Application\Query\ConversationRow;
use App\Messaging\Application\Query\ConversationThread;
use App\Messaging\Application\Query\ThreadAttachment;
use App\Messaging\Application\Query\ThreadMessage;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Lectures de la messagerie du point de vue d'un participant. Côté artisan, le client n'est désigné que par son prénom
 * (RG-112) ; côté client, l'artisan par son nom commercial (logo seulement s'il est public).
 */
final readonly class DbalConversationReader implements ConversationReader
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const string ROWS = <<<'SQL'
        SELECT c.id, c.kind, c.request_id, c.artisan_id, c.blocked_by, c.last_message_at, p.archived, p.last_read_at, p.note,
               EXISTS (SELECT 1 FROM quote.quote aq WHERE aq.request_id = c.request_id AND aq.artisan_id = c.artisan_id AND aq.status = 'accepted') AS is_client,
               (c.client_user_id = :u) AS as_client,
               CASE WHEN c.client_user_id = :u THEN coalesce(a.trade_name, a.company_name, 'Professionnel') ELSE coalesce(u.first_name, 'Client') END AS name,
               CASE WHEN c.client_user_id = :u AND a.logo_file_id IS NOT NULL AND a.status = 'verified' AND a.hidden_at IS NULL THEN a.id END AS logo,
               r.title, r.number, coalesce(cm.name, a.hq_city) AS city,
               (SELECT count(*) FROM messaging.message x WHERE x.conversation_id = c.id AND x.sender_user_id <> :u AND x.created_at > coalesce(p.last_read_at, '-infinity')) AS unread
        FROM messaging.conversation c
        JOIN messaging.participant p ON p.conversation_id = c.id AND p.user_id = :u
        JOIN artisan.artisan_profile a ON a.id = c.artisan_id
        JOIN identity.user_account u ON u.id = c.client_user_id
        LEFT JOIN request.quote_request r ON r.id = c.request_id
        LEFT JOIN catalog.commune cm ON cm.insee = r.city_insee
        WHERE (c.client_user_id = :u OR c.artisan_user_id = :u) AND c.last_message_at IS NOT NULL
        SQL;

    public function __construct(private Connection $connection)
    {
    }

    public function list(string $userId, string $filter, string $query, int $page, int $perPage): array
    {
        if (1 !== preg_match(self::UUID, $userId)) {
            return ['rows' => [], 'total' => 0];
        }
        $where = match ($filter) {
            'archived' => 't.archived',
            'unread' => 'NOT t.archived AND t.unread > 0',
            'clients' => 'NOT t.archived AND t.is_client',
            'prospects' => 'NOT t.archived AND NOT t.is_client',
            default => 'NOT t.archived',
        };
        $params = ['u' => $userId, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage];
        if ('' !== $query) {
            $where .= " AND (t.name ILIKE :q ESCAPE '\\' OR t.title ILIKE :q ESCAPE '\\' OR t.number ILIKE :q ESCAPE '\\')";
            $params['q'] = '%'.addcslashes($query, '%_\\').'%';
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT t.*, last.body AS last_body, last.sender_user_id AS last_sender, count(*) OVER () AS total FROM ('.self::ROWS.') t'
            .' LEFT JOIN LATERAL (SELECT body, sender_user_id FROM messaging.message m WHERE m.conversation_id = t.id ORDER BY m.created_at DESC, m.id DESC LIMIT 1) last ON true'
            .' WHERE '.$where.' ORDER BY t.last_message_at DESC LIMIT :limit OFFSET :offset',
            $params,
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return [
            'rows' => array_map(static fn (array $r): ConversationRow => new ConversationRow(
                DbalValue::string($r['id']),
                DbalValue::string($r['name']),
                DbalValue::nullableString($r['logo']),
                DbalValue::nullableString($r['title']),
                DbalValue::nullableString($r['number']),
                DbalValue::nullableString($r['last_body']),
                null === $r['last_message_at'] ? null : new \DateTimeImmutable(DbalValue::string($r['last_message_at'])),
                $userId === $r['last_sender'],
                DbalValue::int($r['unread']),
                true === $r['archived'],
                null !== $r['blocked_by'],
            ), $rows),
            'total' => [] === $rows ? 0 : DbalValue::int($rows[0]['total']),
        ];
    }

    public function thread(string $userId, string $conversationId): ?ConversationThread
    {
        if (1 !== preg_match(self::UUID, $userId)) {
            return null;
        }
        $t = $this->connection->fetchAssociative(
            'SELECT t.*, q.number AS quote_number, q.status AS quote_status, other.last_read_at AS other_read_at FROM ('.self::ROWS.' AND c.id = :id) t'
            .' LEFT JOIN quote.quote q ON q.request_id = t.request_id AND q.artisan_id = t.artisan_id AND q.status <> \'draft\''
            .' LEFT JOIN messaging.participant other ON other.conversation_id = t.id AND other.user_id <> :u',
            ['u' => $userId, 'id' => $conversationId],
        );
        if (false === $t) {
            return null;
        }
        $otherRead = null === $t['other_read_at'] ? null : new \DateTimeImmutable(DbalValue::string($t['other_read_at']));
        $messages = array_map(static function (array $m) use ($userId, $otherRead): ThreadMessage {
            $at = new \DateTimeImmutable(DbalValue::string($m['created_at']));
            $mine = $userId === $m['sender_user_id'];

            $attachment = null === $m['file_id'] ? null : new ThreadAttachment(DbalValue::string($m['file_id']), DbalValue::nullableString($m['original_name']), DbalValue::string($m['mime']), DbalValue::int($m['size']), DbalValue::string($m['file_status']));

            return new ThreadMessage(DbalValue::string($m['id']), $mine, DbalValue::string($m['body']), $at, null !== $m['original_sealed'], $mine && null !== $otherRead && $otherRead >= $at, $userId === $m['reported_by'], $attachment);
        }, $this->connection->fetchAllAssociative(
            'SELECT m.id, m.sender_user_id, m.body, m.original_sealed, m.created_at, m.reported_by, a.file_id, a.original_name, f.mime, f.size, f.status AS file_status'
            .' FROM messaging.message m LEFT JOIN messaging.attachment a ON a.message_id = m.id LEFT JOIN shared.stored_file f ON f.id = a.file_id'
            .' WHERE m.conversation_id = :c ORDER BY m.created_at, m.id',
            ['c' => $conversationId],
        ));
        $blocked = null === $t['blocked_by'] ? null : ($userId === $t['blocked_by'] ? 'me' : 'other');

        return new ConversationThread(
            DbalValue::string($t['id']),
            DbalValue::string($t['kind']),
            DbalValue::string($t['name']),
            DbalValue::nullableString($t['logo']),
            DbalValue::nullableString($t['city']),
            DbalValue::string($t['artisan_id']),
            DbalValue::nullableString($t['request_id']),
            DbalValue::nullableString($t['title']),
            DbalValue::nullableString($t['number']),
            DbalValue::nullableString($t['quote_number']),
            DbalValue::nullableString($t['quote_status']),
            'accepted' === $t['quote_status'],
            true === $t['archived'],
            $blocked,
            $messages,
            DbalValue::nullableString($t['note']),
        );
    }

    public function counts(string $userId): array
    {
        $zero = ['all' => 0, 'unread' => 0, 'clients' => 0, 'prospects' => 0, 'archived' => 0];
        if (1 !== preg_match(self::UUID, $userId)) {
            return $zero;
        }
        $r = $this->connection->fetchAssociative(
            'SELECT count(*) FILTER (WHERE NOT t.archived) AS "all", count(*) FILTER (WHERE NOT t.archived AND t.unread > 0) AS unread,'
            .' count(*) FILTER (WHERE NOT t.archived AND t.is_client) AS clients, count(*) FILTER (WHERE NOT t.archived AND NOT t.is_client) AS prospects,'
            .' count(*) FILTER (WHERE t.archived) AS archived FROM ('.self::ROWS.') t',
            ['u' => $userId],
        );

        return false === $r ? $zero : array_map(static fn (mixed $v): int => DbalValue::int($v), array_intersect_key($r, $zero)) + $zero;
    }

    public function averageResponseMinutes(string $artisanId, \DateTimeImmutable $since, int $minSamples): ?int
    {
        if (1 !== preg_match(self::UUID, $artisanId)) {
            return null;
        }
        // Par conversation : premier message du client, puis première réponse de l'artisan qui le suit.
        $r = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT count(*) AS samples, avg(extract(epoch FROM f.answered_at - f.asked_at)) / 60 AS minutes FROM (
                    SELECT q.created_at AS asked_at,
                           (SELECT min(a.created_at) FROM messaging.message a WHERE a.conversation_id = c.id AND a.sender_user_id = c.artisan_user_id AND a.created_at >= q.created_at) AS answered_at
                    FROM messaging.conversation c
                    JOIN LATERAL (SELECT min(m.created_at) AS created_at FROM messaging.message m WHERE m.conversation_id = c.id AND m.sender_user_id = c.client_user_id) q ON q.created_at IS NOT NULL
                    WHERE c.artisan_id = :a AND q.created_at >= :since
                ) f WHERE f.answered_at IS NOT NULL
                SQL,
            ['a' => $artisanId, 'since' => $since->format(\DATE_ATOM)],
        );
        if (false === $r || DbalValue::int($r['samples']) < $minSamples || null === $r['minutes']) {
            return null;
        }

        return max(1, (int) round((float) DbalValue::string($r['minutes'])));
    }

    public function attachmentOf(string $userId, string $conversationId, string $fileId): bool
    {
        if (1 !== preg_match(self::UUID, $userId) || 1 !== preg_match(self::UUID, $conversationId) || 1 !== preg_match(self::UUID, $fileId)) {
            return false;
        }

        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM messaging.attachment a JOIN messaging.conversation c ON c.id = a.conversation_id WHERE a.file_id = :f AND c.id = :c AND (c.client_user_id = :u OR c.artisan_user_id = :u)',
            ['f' => $fileId, 'c' => $conversationId, 'u' => $userId],
        );
    }

    public function unreadCount(string $userId): int
    {
        if (1 !== preg_match(self::UUID, $userId)) {
            return 0;
        }

        return DbalValue::int($this->connection->fetchOne(
            <<<'SQL'
                SELECT count(*) FROM messaging.participant p
                WHERE p.user_id = :u AND NOT p.archived AND EXISTS (
                    SELECT 1 FROM messaging.message m WHERE m.conversation_id = p.conversation_id AND m.sender_user_id <> :u AND m.created_at > coalesce(p.last_read_at, '-infinity'))
                SQL,
            ['u' => $userId],
        ));
    }

    public function idFor(string $userId, ?string $requestId, ?string $artisanId): ?string
    {
        $sql = 'SELECT c.id FROM messaging.conversation c LEFT JOIN artisan.artisan_profile a ON a.user_id = :u WHERE (c.client_user_id = :u OR c.artisan_user_id = :u)';
        $params = ['u' => $userId];
        if (null !== $requestId) {
            $sql .= ' AND c.request_id = :r AND c.artisan_id = coalesce(:a, a.id)';
            $params += ['r' => $requestId, 'a' => $artisanId];
        } else {
            $sql .= " AND c.kind = 'pre_question' AND c.artisan_id = :a";
            $params['a'] = $artisanId;
        }
        $id = $this->connection->fetchOne($sql.' LIMIT 1', $params);

        return false === $id ? null : DbalValue::string($id);
    }

    public function message(string $messageId): ?array
    {
        $r = $this->connection->fetchAssociative('SELECT m.body, coalesce(u.first_name, \'\') AS first_name FROM messaging.message m LEFT JOIN identity.user_account u ON u.id = m.sender_user_id WHERE m.id = :id', ['id' => $messageId]);

        return false === $r ? null : ['body' => DbalValue::string($r['body']), 'sender_first_name' => DbalValue::string($r['first_name'])];
    }
}
