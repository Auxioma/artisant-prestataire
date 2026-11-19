<?php

declare(strict_types=1);

namespace App\QuoteRequest\Infrastructure\Admin;

use App\Shared\Application\Admin\DashboardCoverage;
use App\Shared\Application\Admin\DashboardCoverageView;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/**
 * BO01.3 — couverture des demandes de la période (hors brouillons et refus), département de la commune des travaux.
 * « Artisans actifs » d'un couple métier × département : artisans vérifiés et visibles (vue de recherche) exerçant ce
 * métier et installés dans ce département — approximation de la zone d'intervention réelle (P-141).
 */
final readonly class DbalDashboardCoverageHandler implements QueryHandler
{
    private const int DEPARTMENTS = 10;
    private const int JOBS = 5;
    private const int GAPS = 10;

    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(DashboardCoverage $query): DashboardCoverageView
    {
        $requests = "SELECT r.id, r.job_id, r.received_quotes_count, c.department_code
                       FROM request.quote_request r JOIN catalog.commune c ON c.insee = r.city_insee
                      WHERE r.submitted_at >= :from AND r.submitted_at < :to AND r.status NOT IN ('draft', 'rejected')";
        $period = ['from' => $query->from->format(\DATE_ATOM), 'to' => $query->to->format(\DATE_ATOM)];

        $departments = array_map(static function (array $r): array {
            $total = DbalValue::int($r['requests']);
            $uncovered = DbalValue::int($r['uncovered']);

            $rate = $uncovered / $total;

            return ['code' => DbalValue::string($r['code']), 'name' => DbalValue::string($r['name']), 'requests' => $total, 'uncovered' => $uncovered, 'rate' => $rate, 'severity' => DashboardCoverageView::severity($rate)];
        }, $this->connection->fetchAllAssociative(
            "SELECT q.department_code AS code, d.name, count(*) AS requests, count(*) FILTER (WHERE q.received_quotes_count = 0) AS uncovered
               FROM ($requests) q JOIN catalog.department d ON d.code = q.department_code
              GROUP BY q.department_code, d.name
              ORDER BY count(*) DESC, q.department_code LIMIT :limit",
            $period + ['limit' => self::DEPARTMENTS],
        ));
        usort($departments, static fn (array $a, array $b): int => [$a['rate'], $a['code']] <=> [$b['rate'], $b['code']]);

        $jobs = array_map(static fn (array $r): array => ['name' => DbalValue::string($r['name']), 'requests' => DbalValue::int($r['requests'])], $this->connection->fetchAllAssociative(
            "SELECT j.name, count(*) AS requests FROM ($requests) q JOIN catalog.job j ON j.id = q.job_id
              GROUP BY j.id, j.name ORDER BY count(*) DESC, j.name LIMIT :limit",
            $period + ['limit' => self::JOBS],
        ));

        $gaps = array_map(static fn (array $r): array => [
            'job_id' => DbalValue::string($r['job_id']), 'job' => DbalValue::string($r['job']),
            'department_code' => DbalValue::string($r['code']), 'department' => DbalValue::string($r['department']),
            'uncovered' => DbalValue::int($r['uncovered']), 'artisans' => DbalValue::int($r['artisans']),
        ], $this->connection->fetchAllAssociative(
            "SELECT q.job_id, j.name AS job, q.department_code AS code, d.name AS department, count(*) AS uncovered,
                    (SELECT count(*) FROM search.artisan_search_view v WHERE q.job_id = ANY (v.job_ids) AND v.department_code = q.department_code) AS artisans
               FROM ($requests) q
               JOIN catalog.job j ON j.id = q.job_id
               JOIN catalog.department d ON d.code = q.department_code
              WHERE q.received_quotes_count = 0
              GROUP BY q.job_id, j.name, q.department_code, d.name
              ORDER BY count(*) DESC, j.name LIMIT :limit",
            $period + ['limit' => self::GAPS],
        ));

        return new DashboardCoverageView($departments, $jobs, $gaps);
    }
}
