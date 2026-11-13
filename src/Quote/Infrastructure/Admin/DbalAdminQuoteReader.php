<?php

declare(strict_types=1);

namespace App\Quote\Infrastructure\Admin;

use App\Quote\Application\Admin\AdminQuoteReader;
use App\Quote\Application\Admin\AdminQuoteRow;
use App\Quote\Application\Admin\AdminQuotes;
use App\Quote\Application\Admin\AdminQuotesPage;
use App\Quote\Domain\Quote\QuoteStatus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/** BO04.2 — devis envoyés (hors brouillons), avec la demande et l'artisan ; aucune donnée personnelle du client. */
final readonly class DbalAdminQuoteReader implements AdminQuoteReader
{
    public function __construct(private Connection $connection)
    {
    }

    public function page(AdminQuotes $query): AdminQuotesPage
    {
        $qb = $this->connection->createQueryBuilder()
            ->from('quote.quote', 'q')
            ->join('q', 'quote.quote_version', 'v', 'v.quote_id = q.id AND v.version = q.current_version')
            ->join('q', 'request.quote_request', 'r', 'r.id = q.request_id')
            ->join('r', 'catalog.job', 'j', 'j.id = r.job_id')
            ->leftJoin('r', 'catalog.commune', 'c', 'c.insee = r.city_insee')
            ->join('q', 'artisan.artisan_profile', 'p', 'p.id = q.artisan_id')
            ->where("q.status <> 'draft'");
        if (null !== $query->status && null !== QuoteStatus::tryFrom($query->status)) {
            $qb->andWhere('q.status = :status')->setParameter('status', $query->status);
        }
        $q = trim((string) $query->q);
        if ('' !== $q) {
            $qb->andWhere('(q.number ILIKE :q OR r.number ILIKE :q OR p.company_name ILIKE :q OR p.trade_name ILIKE :q OR j.name ILIKE :q OR c.name ILIKE :q)')
                ->setParameter('q', '%'.addcslashes($q, '%_\\').'%');
        }
        $total = DbalValue::int((clone $qb)->select('count(*)')->executeQuery()->fetchOne());
        $rows = $qb->select(
            'q.id',
            'q.number',
            'q.status',
            'q.current_version',
            'q.sent_at',
            'q.valid_until',
            'v.total_ttc_cents',
            'r.id AS request_id',
            'r.number AS request_number',
            'j.name AS job_name',
            'c.name AS city',
            'r.postal_code',
            "coalesce(nullif(p.trade_name, ''), p.company_name, '—') AS artisan",
        )->orderBy('q.sent_at', 'DESC')->addOrderBy('q.id', 'DESC')
            ->setFirstResult((max(1, $query->page) - 1) * $query->perPage)->setMaxResults($query->perPage)
            ->executeQuery()->fetchAllAssociative();
        $counts = [];
        foreach ($this->connection->fetchAllAssociative("SELECT status, count(*) AS n FROM quote.quote WHERE status <> 'draft' GROUP BY status") as $row) {
            $counts[DbalValue::string($row['status'])] = DbalValue::int($row['n']);
        }
        $date = static fn (mixed $v): ?\DateTimeImmutable => null === $v ? null : new \DateTimeImmutable(DbalValue::string($v));

        return new AdminQuotesPage(array_map(static fn (array $r): AdminQuoteRow => new AdminQuoteRow(
            DbalValue::string($r['id']),
            DbalValue::nullableString($r['number']) ?? '—',
            QuoteStatus::from(DbalValue::string($r['status'])),
            DbalValue::int($r['current_version']),
            DbalValue::int($r['total_ttc_cents']),
            $date($r['sent_at']),
            $date($r['valid_until']),
            DbalValue::string($r['request_id']),
            DbalValue::string($r['request_number']),
            DbalValue::string($r['job_name']),
            (DbalValue::nullableString($r['city']) ?? '').' ('.DbalValue::string($r['postal_code']).')',
            DbalValue::string($r['artisan']),
        ), $rows), $total, max(1, $query->page), $query->perPage, $counts);
    }
}
