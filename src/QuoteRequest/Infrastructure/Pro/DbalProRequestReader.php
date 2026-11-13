<?php

declare(strict_types=1);

namespace App\QuoteRequest\Infrastructure\Pro;

use App\QuoteRequest\Application\Port\ProRequestReader;
use App\QuoteRequest\Application\Pro\IgnoreReason;
use App\QuoteRequest\Application\Pro\ProArtisan;
use App\QuoteRequest\Application\Pro\ProRequestCard;
use App\QuoteRequest\Application\Pro\ProRequestFilter;
use App\QuoteRequest\Application\Pro\RequestState;
use App\QuoteRequest\Domain\Request\BudgetRange;
use App\QuoteRequest\Domain\Request\ProjectType;
use App\QuoteRequest\Domain\Request\Timing;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/** Lecture SQL (référentiels Catalog et profils artisans en lecture seule) ; distance PostGIS depuis la commune de départ. */
final readonly class DbalProRequestReader implements ProRequestReader
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const int METERS_PER_KM = 1000;
    private const array COLUMNS = [
        'r.id', 'r.number', 'j.name AS job_name', 'r.title', 'r.project_type', 'coalesce(c.name, r.postal_code) AS city_name', 'r.postal_code',
        'CASE WHEN ac.centroid IS NULL OR c.centroid IS NULL THEN NULL ELSE round(ST_Distance(ac.centroid, c.centroid) / 1000)::int END AS distance_km',
        'r.timing', 'r.budget_range', 'r.submitted_at', '(SELECT count(*) FROM request.request_attachment a WHERE a.request_id = r.id) AS photos',
        'coalesce(r.target_artisan_id = :artisan, false) AS direct', 'r.active_quotes_count', 'r.max_quotes', 'r.status', 'd.viewed_at', 'd.ignored_at',
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function artisanOf(string $userId): ?ProArtisan
    {
        if (1 !== preg_match(self::UUID, $userId)) {
            return null;
        }
        $row = $this->connection->fetchAssociative("SELECT id, status, area_center_insee FROM artisan.artisan_profile WHERE user_id = :u AND status IN ('verified', 'pending_verification')", ['u' => $userId]);
        if (false === $row) {
            return null;
        }
        $jobs = [];
        foreach ($this->connection->fetchAllAssociative('SELECT j.id, j.name FROM artisan.artisan_job aj JOIN catalog.job j ON j.id = aj.job_id WHERE aj.artisan_id = :a ORDER BY aj.is_main DESC, j.name', ['a' => $row['id']]) as $job) {
            $jobs[DbalValue::string($job['id'])] = DbalValue::string($job['name']);
        }

        return new ProArtisan(DbalValue::string($row['id']), $userId, 'verified' === $row['status'], $jobs, DbalValue::nullableString($row['area_center_insee']));
    }

    public function page(ProArtisan $artisan, ProRequestFilter $filter, int $page, int $perPage, \DateTimeImmutable $now): array
    {
        $qb = $this->source($artisan);
        if (null !== $filter->jobId && isset($artisan->jobs[$filter->jobId])) {
            $qb->andWhere('r.job_id = :job')->setParameter('job', $filter->jobId);
        }
        if (null !== $filter->maxKm && $filter->maxKm > 0) {
            $qb->andWhere('ac.centroid IS NOT NULL AND c.centroid IS NOT NULL AND ST_DWithin(ac.centroid, c.centroid, :meters)')->setParameter('meters', self::METERS_PER_KM * $filter->maxKm);
        }
        if (null !== Timing::tryFrom($filter->timing ?? '')) {
            $qb->andWhere('r.timing = :timing')->setParameter('timing', $filter->timing);
        }
        if (null !== $filter->sinceDays && $filter->sinceDays > 0) {
            $qb->andWhere('r.submitted_at >= :since')->setParameter('since', $now->modify(\sprintf('-%d days', $filter->sinceDays))->format(\DATE_ATOM));
        }
        if (null !== BudgetRange::tryFrom($filter->budget ?? '')) {
            $qb->andWhere('r.budget_range = :budget')->setParameter('budget', $filter->budget);
        }
        if ($filter->directOnly) {
            $qb->andWhere('r.target_artisan_id = :artisan');
        }
        match ($filter->state) {
            RequestState::Unread => $qb->andWhere("d.viewed_at IS NULL AND d.ignored_at IS NULL AND r.status = 'open'"),
            RequestState::Viewed => $qb->andWhere('d.viewed_at IS NOT NULL AND d.ignored_at IS NULL'),
            RequestState::Ignored => $qb->andWhere('d.ignored_at IS NOT NULL'),
            default => $qb->andWhere('d.ignored_at IS NULL'),
        };
        $total = DbalValue::int((clone $qb)->select('count(*)')->executeQuery()->fetchOne());
        match ($filter->sort) {
            ProRequestFilter::SORT_CLOSEST => $qb->orderBy('distance_km', 'ASC NULLS LAST'),
            ProRequestFilter::SORT_URGENT => $qb->orderBy("(r.timing = 'urgent')", 'DESC'),
            default => $qb->orderBy("(r.status = 'open')", 'DESC'),
        };
        $rows = $qb->select(...self::COLUMNS)->addOrderBy('r.submitted_at', 'DESC')->addOrderBy('r.id')
            ->setFirstResult((max(1, $page) - 1) * $perPage)->setMaxResults($perPage)->executeQuery()->fetchAllAssociative();

        return ['items' => array_map(fn (array $row): ProRequestCard => $this->hydrate($row, $artisan), $rows), 'total' => $total];
    }

    public function card(ProArtisan $artisan, string $requestId): ?ProRequestCard
    {
        if (1 !== preg_match(self::UUID, $requestId)) {
            return null;
        }
        $row = $this->source($artisan)->select(...self::COLUMNS)->andWhere('r.id = :id')->setParameter('id', $requestId)->executeQuery()->fetchAssociative();

        return false === $row ? null : $this->hydrate($row, $artisan);
    }

    public function photos(string $requestId): array
    {
        return array_map(DbalValue::string(...), $this->connection->fetchFirstColumn(
            "SELECT a.file_id FROM request.request_attachment a JOIN shared.stored_file f ON f.id = a.file_id AND f.status = 'available' WHERE a.request_id = :r ORDER BY a.position",
            ['r' => $requestId],
        ));
    }

    public function markViewed(string $artisanId, string $requestId, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement('UPDATE request.request_dispatch SET viewed_at = :at WHERE request_id = :r AND artisan_id = :a AND viewed_at IS NULL', ['at' => $at->format(\DATE_ATOM), 'r' => $requestId, 'a' => $artisanId]);
    }

    public function ignore(string $artisanId, string $requestId, ?IgnoreReason $reason, bool $restore, \DateTimeImmutable $at): bool
    {
        return 1 === (int) $this->connection->executeStatement(
            'UPDATE request.request_dispatch SET ignored_at = :at, ignore_reason = :reason WHERE request_id = :r AND artisan_id = :a',
            ['at' => $restore ? null : $at->format(\DATE_ATOM), 'reason' => $restore ? null : $reason?->value, 'r' => $requestId, 'a' => $artisanId],
        );
    }

    /**
     * Vérifié : demandes reçues, ouvertes ou déjà consultées (RG-111). En attente : aperçu Q15 des demandes ouvertes
     * de ses métiers dans sa zone, sans ligne de diffusion.
     */
    private function source(ProArtisan $artisan): QueryBuilder
    {
        $qb = $this->connection->createQueryBuilder()->from('request.quote_request', 'r');
        if ($artisan->verified) {
            $qb->join('r', 'request.request_dispatch', 'd', 'd.request_id = r.id AND d.artisan_id = :artisan')
                ->where("(r.status = 'open' OR d.viewed_at IS NOT NULL)");
        } else {
            $qb->leftJoin('r', 'request.request_dispatch', 'd', 'd.request_id = r.id AND d.artisan_id = :artisan')
                ->where("r.status = 'open' AND r.job_id IN (:jobs)")
                ->andWhere('EXISTS (SELECT 1 FROM artisan.service_area_commune z WHERE z.artisan_id = :artisan AND z.insee = r.city_insee)')
                ->setParameter('jobs', [] === $artisan->jobs ? [''] : array_keys($artisan->jobs), ArrayParameterType::STRING);
        }

        return $qb->join('r', 'catalog.job', 'j', 'j.id = r.job_id')
            ->leftJoin('r', 'catalog.commune', 'c', 'c.insee = r.city_insee')
            ->leftJoin('r', 'catalog.commune', 'ac', 'ac.insee = :center')
            ->setParameter('artisan', $artisan->artisanId)
            ->setParameter('center', $artisan->centerInsee ?? '');
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row, ProArtisan $artisan): ProRequestCard
    {
        $timing = Timing::tryFrom(DbalValue::string($row['timing']));
        $budget = BudgetRange::tryFrom(DbalValue::nullableString($row['budget_range']) ?? '');
        $jobName = DbalValue::string($row['job_name']);
        $title = trim(DbalValue::nullableString($row['title']) ?? '');
        $state = match (true) {
            !$artisan->verified => RequestState::Preview,
            null !== $row['ignored_at'] => RequestState::Ignored,
            'open' !== $row['status'] => RequestState::Unavailable,
            null !== $row['viewed_at'] => RequestState::Viewed,
            default => RequestState::Unread,
        };

        return new ProRequestCard(
            DbalValue::string($row['id']),
            DbalValue::string($row['number']),
            $jobName,
            '' !== $title ? $title : (ProjectType::tryFrom(DbalValue::string($row['project_type']))?->label() ?? 'Projet').' · '.$jobName,
            DbalValue::string($row['city_name']).' ('.DbalValue::string($row['postal_code']).')',
            null === $row['distance_km'] ? null : DbalValue::int($row['distance_km']),
            $timing?->label() ?? DbalValue::string($row['timing']),
            Timing::Urgent === $timing,
            null === $budget || BudgetRange::Unknown === $budget ? null : $budget->label(),
            new \DateTimeImmutable(DbalValue::string($row['submitted_at'])),
            DbalValue::int($row['photos']),
            true === $row['direct'],
            DbalValue::int($row['active_quotes_count']),
            DbalValue::int($row['max_quotes']),
            $state,
        );
    }
}
