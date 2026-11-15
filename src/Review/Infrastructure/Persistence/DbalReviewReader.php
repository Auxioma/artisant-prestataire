<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Persistence;

use App\Review\Application\Query\ReviewReader;
use App\Review\Application\Query\ReviewView;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class DbalReviewReader implements ReviewReader
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const string VIEW = <<<'SQL'
        SELECT v.*, r.number AS request_number, r.title AS request_title, cm.name AS city,
               coalesce(p.trade_name, p.company_name, 'Professionnel') AS artisan_name,
               CASE WHEN uc.status = 'anonymized' THEN 'Client vérifié' ELSE coalesce(uc.first_name, 'Client') END AS client_first_name, CASE WHEN uc.status = 'anonymized' THEN NULL ELSE left(uc.last_name, 1) END AS client_last_initial,
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

    public function publishedOf(string $artisanId): array
    {
        if (1 !== preg_match(self::UUID, $artisanId)) {
            return [];
        }

        return array_map(self::view(...), $this->connection->fetchAllAssociative(self::VIEW." WHERE v.artisan_id = :a AND v.status = 'published' ORDER BY v.service_date DESC, v.created_at DESC", ['a' => $artisanId]));
    }

    public function stats(string $artisanId, \DateTimeImmutable $before): array
    {
        $empty = ['average' => null, 'count' => 0, 'distribution' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0], 'criteria' => [], 'previous' => null];
        if (1 !== preg_match(self::UUID, $artisanId)) {
            return $empty;
        }
        $rows = $this->connection->fetchAllAssociative("SELECT rating, sub_ratings, created_at FROM review.review WHERE artisan_id = :a AND status = 'published'", ['a' => $artisanId]);
        if ([] === $rows) {
            return $empty;
        }
        $distribution = $empty['distribution'];
        $sums = [];
        $counts = [];
        $old = [];
        foreach ($rows as $r) {
            $rating = DbalValue::int($r['rating']);
            ++$distribution[$rating];
            if (new \DateTimeImmutable(DbalValue::string($r['created_at'])) < $before) {
                $old[] = $rating;
            }
            $sub = DbalValue::json($r['sub_ratings']);
            foreach (\is_array($sub) ? $sub : [] as $k => $v) {
                if (\is_string($k) && \is_int($v)) {
                    $sums[$k] = ($sums[$k] ?? 0) + $v;
                    $counts[$k] = ($counts[$k] ?? 0) + 1;
                }
            }
        }
        $criteria = [];
        foreach ($sums as $k => $sum) {
            $criteria[$k] = round($sum / $counts[$k], 1);
        }
        $total = array_sum(array_map(static fn (array $r): int => DbalValue::int($r['rating']), $rows));

        return [
            'average' => round($total / \count($rows), 1),
            'count' => \count($rows),
            'distribution' => $distribution,
            'criteria' => $criteria,
            'previous' => [] === $old ? null : round(array_sum($old) / \count($old), 1),
        ];
    }

    public function solicitable(string $artisanId): array
    {
        if (1 !== preg_match(self::UUID, $artisanId)) {
            return [];
        }
        $d = static fn (mixed $v): ?\DateTimeImmutable => null === $v ? null : new \DateTimeImmutable(DbalValue::string($v));

        return array_map(static fn (array $r): array => [
            'request_id' => DbalValue::string($r['id']),
            'request_title' => DbalValue::nullableString($r['title']),
            'request_number' => DbalValue::string($r['number']),
            'client_name' => trim(DbalValue::string($r['first_name']).' '.DbalValue::string($r['initial']).'.'),
            'accepted_at' => new \DateTimeImmutable(DbalValue::string($r['decided_at'])),
            'service_at' => $d($r['service_at']),
            'artisan_reminded_at' => $d($r['artisan_reminded_at']),
        ], $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT r.id, r.title, r.number, q.decided_at, i.artisan_reminded_at, coalesce(u.first_name, 'Client') AS first_name, coalesce(left(u.last_name, 1), '') AS initial,
                       (SELECT max(a.starts_at) FROM quote.appointment a WHERE a.quote_id = q.id AND (a.status = 'completed' OR (a.status = 'confirmed' AND a.starts_at <= now()))) AS service_at
                FROM quote.quote q
                JOIN request.quote_request r ON r.id = q.request_id
                LEFT JOIN identity.user_account u ON u.id = r.client_id AND u.status <> 'anonymized'
                LEFT JOIN review.invitation i ON i.request_id = r.id
                WHERE q.artisan_id = :a AND q.status = 'accepted' AND q.decided_at IS NOT NULL
                  AND NOT EXISTS (SELECT 1 FROM review.review v WHERE v.request_id = r.id)
                ORDER BY q.decided_at DESC
                SQL,
            ['a' => $artisanId],
        ));
    }

    public function publicPage(string $artisanId, string $sort, string $filter, int $limit): array
    {
        if (1 !== preg_match(self::UUID, $artisanId)) {
            return ['items' => [], 'matching' => 0];
        }
        $where = " WHERE v.artisan_id = :a AND v.status = 'published'".match ($filter) {
            '5' => ' AND v.rating = 5',
            '4' => ' AND v.rating = 4',
            '3-et-moins' => ' AND v.rating <= 3',
            default => '',
        };
        $order = match ($sort) {
            'meilleurs' => 'v.rating DESC, v.service_date DESC',
            'moins-bons' => 'v.rating ASC, v.service_date DESC',
            default => 'v.service_date DESC, v.created_at DESC',
        };
        $rows = $this->connection->fetchAllAssociative(self::VIEW.$where.' ORDER BY '.$order.' LIMIT :l', ['a' => $artisanId, 'l' => $limit], ['l' => ParameterType::INTEGER]);
        $matching = DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM review.review v'.$where, ['a' => $artisanId]));

        return ['items' => array_map(self::view(...), $rows), 'matching' => $matching];
    }

    public function moderationQueue(int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT x.*, q.number AS quote_number, q.decided_at,'
            ." (SELECT max(a.starts_at) FROM quote.appointment a WHERE a.quote_id = q.id AND a.status IN ('completed', 'confirmed') AND a.starts_at <= now()) AS service_at,"
            .' (SELECT count(*) FROM review.review o WHERE o.client_user_id = x.client_user_id) AS author_reviews,'
            ." (SELECT count(*) FROM review.review o WHERE o.client_user_id = x.client_user_id AND o.status IN ('rejected', 'hidden')) AS author_rejections"
            .' FROM ('.self::VIEW.") x JOIN quote.quote q ON q.id = x.quote_id
             WHERE x.status = 'pending' OR x.reply_status = 'pending' OR (x.status = 'published' AND x.reported_at IS NOT NULL)
             ORDER BY coalesce(x.reported_at, x.reply_at, x.updated_at) LIMIT :l",
            ['l' => $limit],
            ['l' => ParameterType::INTEGER],
        );
        $d = static fn (mixed $v): ?\DateTimeImmutable => null === $v ? null : new \DateTimeImmutable(DbalValue::string($v));

        return array_map(static fn (array $r): array => [
            'view' => self::view($r),
            'kind' => 'pending' === $r['status'] ? 'review' : ('pending' === $r['reply_status'] ? 'reply' : 'report'),
            'quote_number' => DbalValue::nullableString($r['quote_number']) ?? '',
            'accepted_at' => $d($r['decided_at']),
            'service_at' => $d($r['service_at']),
            'author_reviews' => DbalValue::int($r['author_reviews']),
            'author_rejections' => DbalValue::int($r['author_rejections']),
        ], $rows);
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
