<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Persistence;

use App\Review\Application\Query\ReviewReader;
use App\Review\Application\Query\ReviewView;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalReviewReader implements ReviewReader
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const string VIEW = <<<'SQL'
        SELECT v.*, r.number AS request_number, r.title AS request_title, cm.name AS city,
               coalesce(p.trade_name, p.company_name, 'Professionnel') AS artisan_name,
               coalesce(uc.first_name, 'Client') AS client_first_name, left(uc.last_name, 1) AS client_last_initial,
               coalesce((SELECT jsonb_agg(f.id ORDER BY ph.ord) FROM jsonb_array_elements_text(v.photos) WITH ORDINALITY ph(id, ord)
                         JOIN shared.stored_file f ON f.id = ph.id::uuid AND f.status = 'available'), '[]'::jsonb) AS available_photos
        FROM review.review v
        JOIN request.quote_request r ON r.id = v.request_id
        JOIN artisan.artisan_profile p ON p.id = v.artisan_id
        LEFT JOIN identity.user_account uc ON uc.id = v.client_user_id AND uc.status <> 'anonymized'
        LEFT JOIN catalog.commune cm ON cm.insee = r.city_insee
        SQL;

    public function __construct(private Connection $connection)
    {
    }

    public function reviewable(string $clientUserId): array
    {
        if (1 !== preg_match(self::UUID, $clientUserId)) {
            return [];
        }

        return array_map(static fn (array $r): array => [
            'request_id' => DbalValue::string($r['request_id']),
            'request_number' => DbalValue::string($r['number']),
            'request_title' => DbalValue::nullableString($r['title']),
            'artisan_id' => DbalValue::string($r['artisan_id']),
            'artisan_name' => DbalValue::string($r['artisan_name']),
            'logo' => DbalValue::nullableString($r['logo']),
            'accepted_at' => new \DateTimeImmutable(DbalValue::string($r['decided_at'])),
            'service_at' => null === $r['service_at'] ? null : new \DateTimeImmutable(DbalValue::string($r['service_at'])),
        ], $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT r.id AS request_id, r.number, r.title, q.artisan_id, q.decided_at,
                       coalesce(p.trade_name, p.company_name, 'Professionnel') AS artisan_name,
                       CASE WHEN p.logo_file_id IS NOT NULL AND p.status = 'verified' AND p.hidden_at IS NULL THEN p.id END AS logo,
                       (SELECT max(a.starts_at) FROM quote.appointment a WHERE a.quote_id = q.id AND (a.status = 'completed' OR (a.status = 'confirmed' AND a.starts_at <= now()))) AS service_at
                FROM request.quote_request r
                JOIN quote.quote q ON q.request_id = r.id AND q.status = 'accepted'
                JOIN artisan.artisan_profile p ON p.id = q.artisan_id
                WHERE r.client_id = :u AND q.decided_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM review.review v WHERE v.request_id = r.id)
                ORDER BY q.decided_at DESC
                SQL,
            ['u' => $clientUserId],
        ));
    }

    public function ofClient(string $clientUserId): array
    {
        if (1 !== preg_match(self::UUID, $clientUserId)) {
            return [];
        }

        return array_map(self::view(...), $this->connection->fetchAllAssociative(self::VIEW.' WHERE v.client_user_id = :u ORDER BY v.created_at DESC', ['u' => $clientUserId]));
    }

    public function one(string $reviewId): ?ReviewView
    {
        if (1 !== preg_match(self::UUID, $reviewId)) {
            return null;
        }
        $r = $this->connection->fetchAssociative(self::VIEW.' WHERE v.id = :id', ['id' => $reviewId]);

        return false === $r ? null : self::view($r);
    }

    /** @param array<string, mixed> $r */
    public static function view(array $r): ReviewView
    {
        $d = static fn (mixed $v): ?\DateTimeImmutable => null === $v ? null : new \DateTimeImmutable(DbalValue::string($v));
        $sub = DbalValue::json($r['sub_ratings']);
        $ratings = [];
        foreach (\is_array($sub) ? $sub : [] as $k => $v) {
            if (\is_string($k) && \is_int($v)) {
                $ratings[$k] = $v;
            }
        }
        $photos = DbalValue::json($r['available_photos']);

        return new ReviewView(
            DbalValue::string($r['id']),
            DbalValue::string($r['request_id']),
            DbalValue::string($r['request_number']),
            DbalValue::nullableString($r['request_title']),
            DbalValue::string($r['artisan_id']),
            DbalValue::string($r['artisan_name']),
            DbalValue::string($r['client_first_name']),
            DbalValue::nullableString($r['client_last_initial']),
            DbalValue::nullableString($r['city']),
            DbalValue::int($r['rating']),
            $ratings,
            DbalValue::nullableString($r['title']),
            DbalValue::string($r['body']),
            new \DateTimeImmutable(DbalValue::string($r['service_date'])),
            null === $r['recommends'] ? null : true === $r['recommends'],
            \is_array($photos) ? array_values(array_filter($photos, 'is_string')) : [],
            DbalValue::string($r['status']),
            DbalValue::int($r['risk_score']),
            DbalValue::nullableString($r['reason_code']),
            DbalValue::nullableString($r['moderator_note']),
            DbalValue::nullableString($r['reply_body']),
            DbalValue::nullableString($r['reply_status']),
            $d($r['reply_at']),
            DbalValue::nullableString($r['reply_reason']),
            $d($r['reported_at']),
            DbalValue::nullableString($r['report_reason']),
            new \DateTimeImmutable(DbalValue::string($r['created_at'])),
            DbalValue::string($r['client_user_id']),
        );
    }
}
