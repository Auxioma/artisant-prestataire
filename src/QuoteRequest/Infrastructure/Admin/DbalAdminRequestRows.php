<?php

declare(strict_types=1);

namespace App\QuoteRequest\Infrastructure\Admin;

use App\QuoteRequest\Application\Admin\AdminRequestList;
use App\QuoteRequest\Application\Admin\AdminRequestPage;
use App\QuoteRequest\Application\Admin\AdminRequestRow;
use App\QuoteRequest\Application\Admin\AdminRequestRows;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/** BO04.1 — projection de lecture : demandes, métier et commune (référentiel Catalog lu en SQL, aucune écriture). */
final readonly class DbalAdminRequestRows implements AdminRequestRows
{
    /** Indicateur « Sans devis > 48 h » (Figma 186:1669) : demandes publiées depuis plus de 48 h sans aucun devis. */
    private const int STALE_HOURS = 48;
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const int SEARCH_MAX = 80;

    public function __construct(private Connection $connection)
    {
    }

    public function page(AdminRequestList $query): AdminRequestPage
    {
        $qb = $this->filtered($query);
        $total = DbalValue::int((clone $qb)->select('count(*)')->executeQuery()->fetchOne());
        $page = max(1, $query->page);
        $rows = $qb->select(...self::COLUMNS)->orderBy("(r.status = 'pending_moderation')", 'DESC')->addOrderBy('r.submitted_at', 'DESC')
            ->setFirstResult(($page - 1) * $query->perPage)->setMaxResults($query->perPage)->executeQuery()->fetchAllAssociative();

        return new AdminRequestPage(array_map(self::hydrate(...), $rows), $total, $page, $query->perPage, $this->kpis());
    }

    public function row(string $requestId): ?AdminRequestRow
    {
        if (1 !== preg_match(self::UUID, $requestId)) {
            return null;
        }
        $row = $this->base()->select(...self::COLUMNS)->where('r.id = :id')->setParameter('id', $requestId)->executeQuery()->fetchAssociative();

        return false === $row ? null : self::hydrate($row);
    }

    public function attachmentCount(string $requestId): int
    {
        return DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM request.request_attachment WHERE request_id = :id', ['id' => $requestId]));
    }

    public function export(AdminRequestList $query): iterable
    {
        foreach ($this->filtered($query)->select(...self::COLUMNS)->orderBy('r.submitted_at', 'DESC')->executeQuery()->iterateAssociative() as $row) {
            yield self::hydrate($row);
        }
    }

    private const array COLUMNS = ['r.id', 'r.number', 'j.name AS job_name', 'coalesce(c.name, r.postal_code) AS city_name', 'r.status', 'r.active_quotes_count', 'r.max_quotes', 'r.risk_score', 'r.source', 'r.submitted_at', '(SELECT count(*) FROM request.request_dispatch d WHERE d.request_id = r.id) AS dispatched'];

    private function base(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()->from('request.quote_request', 'r')
            ->join('r', 'catalog.job', 'j', 'j.id = r.job_id')
            ->leftJoin('r', 'catalog.commune', 'c', 'c.insee = r.city_insee');
    }

    private function filtered(AdminRequestList $query): QueryBuilder
    {
        $qb = $this->base();
        $search = trim(mb_substr($query->search, 0, self::SEARCH_MAX));
        if ('' !== $search) {
            $qb->andWhere('(r.number ILIKE :q OR j.name ILIKE :q OR c.name ILIKE :q)')->setParameter('q', '%'.addcslashes($search, '%_\\').'%');
        }
        if (null !== $query->status) {
            $qb->andWhere('r.status = :status')->setParameter('status', $query->status);
        }
        if (null !== $query->jobId && 1 === preg_match(self::UUID, $query->jobId)) {
            $qb->andWhere('r.job_id = :job')->setParameter('job', $query->jobId);
        }
        if (null !== $query->department) {
            $qb->andWhere('c.department_code = :dep')->setParameter('dep', $query->department);
        }
        if (null !== $query->source) {
            $qb->andWhere('r.source = :source')->setParameter('source', $query->source);
        }
        if (null !== $query->minRisk) {
            $qb->andWhere('r.risk_score >= :risk')->setParameter('risk', $query->minRisk);
        }

        return $qb;
    }

    /** @return array{all: int, pending: int, open: int, full: int, stale: int, quotes: int} */
    private function kpis(): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT count(*) FILTER (WHERE status <> 'draft') AS all_requests,
                    count(*) FILTER (WHERE status = 'pending_moderation') AS pending,
                    count(*) FILTER (WHERE status = 'open') AS open,
                    count(*) FILTER (WHERE status = 'full') AS full,
                    count(*) FILTER (WHERE status = 'open' AND received_quotes_count = 0 AND published_at < now() - make_interval(hours => :stale)) AS stale,
                    coalesce(sum(received_quotes_count), 0) AS quotes
             FROM request.quote_request",
            ['stale' => self::STALE_HOURS],
        ) ?: [];

        return ['all' => DbalValue::int($row['all_requests'] ?? 0), 'pending' => DbalValue::int($row['pending'] ?? 0), 'open' => DbalValue::int($row['open'] ?? 0), 'full' => DbalValue::int($row['full'] ?? 0), 'stale' => DbalValue::int($row['stale'] ?? 0), 'quotes' => DbalValue::int($row['quotes'] ?? 0)];
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): AdminRequestRow
    {
        return new AdminRequestRow(
            DbalValue::string($row['id']),
            DbalValue::string($row['number']),
            DbalValue::string($row['job_name']),
            DbalValue::string($row['city_name']),
            DbalValue::string($row['status']),
            DbalValue::int($row['active_quotes_count']),
            DbalValue::int($row['max_quotes']),
            DbalValue::int($row['risk_score']),
            DbalValue::string($row['source']),
            new \DateTimeImmutable(DbalValue::string($row['submitted_at'])),
            DbalValue::int($row['dispatched']),
        );
    }
}
