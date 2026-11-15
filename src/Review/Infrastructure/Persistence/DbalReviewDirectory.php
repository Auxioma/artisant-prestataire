<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Persistence;

use App\Review\Application\Port\ReviewDirectory;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalReviewDirectory implements ReviewDirectory
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private Connection $connection)
    {
    }

    public function acceptedQuote(string $clientUserId, string $requestId): ?array
    {
        if (1 !== preg_match(self::UUID, $requestId) || 1 !== preg_match(self::UUID, $clientUserId)) {
            return null;
        }
        $r = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT q.id, q.artisan_id, q.decided_at,
                       (SELECT max(a.starts_at) FROM quote.appointment a WHERE a.quote_id = q.id AND (a.status = 'completed' OR (a.status = 'confirmed' AND a.starts_at <= now()))) AS service_at
                FROM quote.quote q JOIN request.quote_request r ON r.id = q.request_id
                WHERE q.request_id = :r AND r.client_id = :u AND q.status = 'accepted'
                SQL,
            ['r' => $requestId, 'u' => $clientUserId],
        );
        if (false === $r || null === $r['decided_at']) {
            return null;
        }

        return [
            'quote_id' => DbalValue::string($r['id']),
            'artisan_id' => DbalValue::string($r['artisan_id']),
            'accepted_at' => new \DateTimeImmutable(DbalValue::string($r['decided_at'])),
            'service_at' => null === $r['service_at'] ? null : new \DateTimeImmutable(DbalValue::string($r['service_at'])),
        ];
    }

    public function artisanOfUser(string $userId): ?string
    {
        if (1 !== preg_match(self::UUID, $userId)) {
            return null;
        }
        $id = $this->connection->fetchOne('SELECT id FROM artisan.artisan_profile WHERE user_id = :u', ['u' => $userId]);

        return false === $id ? null : DbalValue::string($id);
    }

    public function parties(string $reviewId): ?array
    {
        if (1 !== preg_match(self::UUID, $reviewId)) {
            return null;
        }
        $r = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT v.request_id, r.number, v.client_user_id, uc.email AS client_email, uc.first_name AS client_first_name, v.rating,
                       p.user_id AS artisan_user_id, ua.email AS artisan_email, ua.first_name AS artisan_first_name, coalesce(p.trade_name, p.company_name, 'Professionnel') AS artisan_name
                FROM review.review v
                JOIN request.quote_request r ON r.id = v.request_id
                JOIN artisan.artisan_profile p ON p.id = v.artisan_id
                LEFT JOIN identity.user_account uc ON uc.id = v.client_user_id AND uc.status <> 'anonymized'
                LEFT JOIN identity.user_account ua ON ua.id = p.user_id AND ua.status <> 'anonymized'
                WHERE v.id = :id
                SQL,
            ['id' => $reviewId],
        );
        if (false === $r) {
            return null;
        }

        return [
            'request_id' => DbalValue::string($r['request_id']), 'request_number' => DbalValue::string($r['number']),
            'client_user_id' => DbalValue::string($r['client_user_id']), 'client_email' => DbalValue::nullableString($r['client_email']), 'client_first_name' => DbalValue::nullableString($r['client_first_name']),
            'artisan_user_id' => DbalValue::string($r['artisan_user_id']), 'artisan_email' => DbalValue::nullableString($r['artisan_email']), 'artisan_first_name' => DbalValue::nullableString($r['artisan_first_name']),
            'artisan_name' => DbalValue::string($r['artisan_name']), 'rating' => DbalValue::int($r['rating']),
        ];
    }

    /** @return array{avg: ?float, count: int} */
    public function publishedStats(string $artisanId): array
    {
        $r = $this->connection->fetchAssociative("SELECT count(*) AS n, avg(rating) AS avg FROM review.review WHERE artisan_id = :a AND status = 'published'", ['a' => $artisanId]);
        $count = false === $r ? 0 : DbalValue::int($r['n']);

        return ['avg' => 0 === $count || false === $r || null === $r['avg'] ? null : round((float) DbalValue::string($r['avg']), 2), 'count' => $count];
    }

    public function invitationCandidates(bool $all = false): array
    {
        $d = static fn (mixed $v): ?\DateTimeImmutable => null === $v ? null : new \DateTimeImmutable(DbalValue::string($v));

        return array_map(static fn (array $r): array => [
            'request_id' => DbalValue::string($r['request_id']),
            'artisan_id' => DbalValue::string($r['artisan_id']),
            'client_user_id' => DbalValue::string($r['client_id']),
            'client_email' => DbalValue::nullableString($r['email']),
            'client_first_name' => DbalValue::nullableString($r['first_name']),
            'artisan_name' => DbalValue::string($r['artisan_name']),
            'accepted_at' => new \DateTimeImmutable(DbalValue::string($r['decided_at'])),
            'service_at' => $d($r['service_at']),
            'invited_at' => $d($r['invited_at']),
            'reminded_at' => $d($r['reminded_at']),
            'artisan_reminded_at' => $d($r['artisan_reminded_at']),
        ], $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT r.id AS request_id, q.artisan_id, r.client_id, u.email, u.first_name, q.decided_at, i.invited_at, i.reminded_at, i.artisan_reminded_at,
                       coalesce(p.trade_name, p.company_name, 'Professionnel') AS artisan_name,
                       (SELECT max(a.starts_at) FROM quote.appointment a WHERE a.quote_id = q.id AND (a.status = 'completed' OR (a.status = 'confirmed' AND a.starts_at <= now()))) AS service_at
                FROM request.quote_request r
                JOIN quote.quote q ON q.request_id = r.id AND q.status = 'accepted' AND q.decided_at IS NOT NULL
                JOIN artisan.artisan_profile p ON p.id = q.artisan_id
                LEFT JOIN identity.user_account u ON u.id = r.client_id AND u.status = 'active'
                LEFT JOIN review.invitation i ON i.request_id = r.id
                WHERE NOT EXISTS (SELECT 1 FROM review.review v WHERE v.request_id = r.id) AND (i.reminded_at IS NULL OR CAST(:all AS boolean))
                ORDER BY q.decided_at
                SQL,
            ['all' => $all ? 'true' : 'false'],
        ));
    }

    public function markInvitation(string $requestId, string $column, \DateTimeImmutable $at): void
    {
        $column = \in_array($column, ['invited_at', 'reminded_at', 'artisan_reminded_at'], true) ? $column : 'invited_at';
        $this->connection->executeStatement(
            'INSERT INTO review.invitation (request_id, '.$column.') VALUES (:r, :at) ON CONFLICT (request_id) DO UPDATE SET '.$column.' = excluded.'.$column,
            ['r' => $requestId, 'at' => $at->format('Y-m-d\\TH:i:sP')],
        );
    }
}
