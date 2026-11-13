<?php

declare(strict_types=1);

namespace App\Quote\Infrastructure\Pro;

use App\Quote\Application\Pro\ProQuoteFilter;
use App\Quote\Application\Pro\ProQuoteReader;
use App\Quote\Application\Pro\ProQuoteRow;
use App\Quote\Domain\Quote\QuoteStatus;
use App\QuoteRequest\Domain\Request\BudgetRange;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * PR05 — lecture SQL des devis d'un artisan avec leur version courante et leur demande (RG-112 : prénom du client et
 * commune seulement). Onglet « Chantiers » : devis acceptés.
 */
final readonly class DbalProQuoteReader implements ProQuoteReader
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const array COLUMNS = [
        'q.id', 'q.request_id', 'q.number', 'q.status', 'q.current_version', 'q.sent_at', 'q.valid_until', 'q.updated_at',
        'v.total_ttc_cents', 'r.number AS request_number', 'r.status AS request_status', 'r.budget_range', 'r.postal_code',
        "coalesce(nullif(r.title, ''), j.name) AS title", 'j.name AS job_name', 'c.name AS city', 'u.first_name',
        "(SELECT a.file_id FROM request.request_attachment a JOIN shared.stored_file f ON f.id = a.file_id AND f.status = 'available' WHERE a.request_id = r.id ORDER BY a.position LIMIT 1) AS photo_id",
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function page(string $artisanId, ProQuoteFilter $filter, int $page, int $perPage): array
    {
        $qb = $this->source($artisanId);
        if (ProQuoteFilter::TAB_WORKS === $filter->tab) {
            $qb->andWhere("q.status = 'accepted'");
        }
        if (null !== $filter->status && null !== QuoteStatus::tryFrom($filter->status)) {
            $qb->andWhere('q.status = :status')->setParameter('status', $filter->status);
        }
        if (null !== $filter->days) {
            $qb->andWhere('q.updated_at >= now() - make_interval(days => :days)')->setParameter('days', $filter->days);
        }
        if (null !== $filter->jobId && 1 === preg_match(self::UUID, $filter->jobId)) {
            $qb->andWhere('r.job_id = :job')->setParameter('job', $filter->jobId);
        }
        $q = trim((string) $filter->q);
        if ('' !== $q) {
            $qb->andWhere('(r.title ILIKE :q OR r.number ILIKE :q OR q.number ILIKE :q OR c.name ILIKE :q OR r.postal_code ILIKE :q OR u.first_name ILIKE :q OR j.name ILIKE :q)')
                ->setParameter('q', '%'.addcslashes($q, '%_\\').'%');
        }
        $total = DbalValue::int((clone $qb)->select('count(*)')->executeQuery()->fetchOne());
        $rows = $qb->select(...self::COLUMNS)
            ->orderBy('coalesce(q.sent_at, q.updated_at)', 'DESC')->addOrderBy('q.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)
            ->executeQuery()->fetchAllAssociative();

        return ['items' => array_map(self::row(...), $rows), 'total' => $total];
    }

    public function stats(string $artisanId): array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT count(*) FILTER (WHERE q.status IN ('sent', 'viewed')) AS pending,
                       count(*) FILTER (WHERE q.status = 'accepted') AS accepted,
                       count(*) FILTER (WHERE q.status = 'accepted' AND r.status = 'awarded') AS works,
                       count(*) FILTER (WHERE q.status = 'accepted' AND r.status = 'completed') AS done,
                       count(*) AS devis
                FROM quote.quote q JOIN request.quote_request r ON r.id = q.request_id
                WHERE q.artisan_id = :a AND q.status <> 'withdrawn'
                SQL,
            ['a' => $artisanId],
        ) ?: [];

        return [
            'pending' => DbalValue::int($row['pending'] ?? 0),
            'accepted' => DbalValue::int($row['accepted'] ?? 0),
            'works' => DbalValue::int($row['works'] ?? 0),
            'done' => DbalValue::int($row['done'] ?? 0),
            'devis' => DbalValue::int($row['devis'] ?? 0),
            'chantiers' => DbalValue::int($row['accepted'] ?? 0),
        ];
    }

    public function jobs(string $artisanId): array
    {
        $jobs = [];
        foreach ($this->connection->fetchAllAssociative('SELECT DISTINCT j.id, j.name FROM quote.quote q JOIN request.quote_request r ON r.id = q.request_id JOIN catalog.job j ON j.id = r.job_id WHERE q.artisan_id = :a ORDER BY j.name', ['a' => $artisanId]) as $job) {
            $jobs[DbalValue::string($job['id'])] = DbalValue::string($job['name']);
        }

        return $jobs;
    }

    private function source(string $artisanId): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->from('quote.quote', 'q')
            ->join('q', 'quote.quote_version', 'v', 'v.quote_id = q.id AND v.version = q.current_version')
            ->join('q', 'request.quote_request', 'r', 'r.id = q.request_id')
            ->join('r', 'catalog.job', 'j', 'j.id = r.job_id')
            ->join('r', 'identity.user_account', 'u', 'u.id = r.client_id')
            ->leftJoin('r', 'catalog.commune', 'c', 'c.insee = r.city_insee')
            ->where('q.artisan_id = :artisan')->andWhere("q.status <> 'withdrawn'")
            ->setParameter('artisan', $artisanId);
    }

    /** @param array<string, mixed> $row */
    private static function row(array $row): ProQuoteRow
    {
        $date = static fn (mixed $v): ?\DateTimeImmutable => null === $v ? null : new \DateTimeImmutable(DbalValue::string($v));
        $city = DbalValue::nullableString($row['city']);
        $postal = DbalValue::string($row['postal_code']);

        return new ProQuoteRow(
            DbalValue::string($row['id']),
            DbalValue::string($row['request_id']),
            DbalValue::nullableString($row['number']),
            QuoteStatus::from(DbalValue::string($row['status'])),
            DbalValue::int($row['current_version']),
            DbalValue::int($row['total_ttc_cents']),
            $date($row['sent_at']),
            $date($row['valid_until']),
            new \DateTimeImmutable(DbalValue::string($row['updated_at'])),
            DbalValue::string($row['request_number']),
            DbalValue::string($row['title']),
            'completed' === $row['request_status'],
            DbalValue::nullableString($row['first_name']),
            null === $city ? $postal : $city.' ('.$postal.')',
            DbalValue::string($row['job_name']),
            BudgetRange::tryFrom(DbalValue::nullableString($row['budget_range']) ?? '')?->label(),
            DbalValue::nullableString($row['photo_id']),
        );
    }
}
