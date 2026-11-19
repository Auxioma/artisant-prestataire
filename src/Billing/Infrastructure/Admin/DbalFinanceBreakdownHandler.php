<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Admin;

use App\Shared\Application\Admin\FinanceBreakdown;
use App\Shared\Application\Admin\FinanceBreakdownView;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/**
 * BO15.3 — détails financiers. Cohortes : artisans regroupés par mois de leur premier abonnement payé (les quatre mois
 * précédant la période) ; rétention à Mk = part de la cohorte ayant une période facturée en cours au milieu du k-ième
 * mois suivant (null tant que ce moment n'est pas atteint).
 */
final readonly class DbalFinanceBreakdownHandler implements QueryHandler
{
    private const int COHORTS = 4;
    private const int MONTHS_PER_YEAR = 12;
    private const string MID_MONTH = '+14 days';
    private const string ANNUAL_LABEL = 'Annuels (lissés)';

    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(FinanceBreakdown $query): FinanceBreakdownView
    {
        $period = ['from' => $query->from->format(\DATE_ATOM), 'to' => $query->to->format(\DATE_ATOM)];
        $at = $query->to->modify('-1 second')->format(\DATE_ATOM);
        $plans = array_map(static fn (array $r): array => ['label' => DbalValue::string($r['label']), 'mrr_cents' => (int) round(DbalValue::nullableFloat($r['mrr']) ?? 0.0)], $this->connection->fetchAllAssociative(
            "SELECT CASE WHEN s.interval = 'year' THEN :annual ELSE p.name END AS label,
                    sum(CASE WHEN s.interval = 'year' THEN coalesce(i.amount_ht_cents, sp.amount_paid_cents)::numeric / :months ELSE coalesce(i.amount_ht_cents, sp.amount_paid_cents) END) AS mrr
               FROM billing.subscription_period sp
               JOIN billing.subscription s ON s.id = sp.subscription_id
               JOIN billing.plan p ON p.id = s.plan_id
               LEFT JOIN billing.invoice i ON i.stripe_invoice_id = sp.stripe_invoice_id
              WHERE sp.period_start <= :at AND sp.period_end > :at
              GROUP BY 1 ORDER BY bool_or(s.interval = 'year'), min(p.position), 1",
            ['at' => $at, 'annual' => self::ANNUAL_LABEL, 'months' => self::MONTHS_PER_YEAR],
        ));

        $money = $this->connection->fetchAssociative(
            "SELECT (SELECT count(DISTINCT artisan_id) FROM billing.invoice WHERE status IN ('open', 'uncollectible') AND issued_at >= :from AND issued_at < :to) AS unpaid,
                    (SELECT count(*) FROM billing.promo_redemption WHERE redeemed_at >= :from AND redeemed_at < :to) AS promos,
                    (SELECT count(*) FROM billing.reconciliation_issue WHERE resolved_at IS NULL) AS issues,
                    (SELECT count(*) FROM billing.processed_stripe_event WHERE received_at >= :from AND received_at < :to) AS hooks,
                    (SELECT count(*) FROM billing.processed_stripe_event WHERE received_at >= :from AND received_at < :to AND status IN ('processed', 'ignored')) AS hooks_ok",
            $period,
        ) ?: [];
        $run = $this->connection->fetchAssociative('SELECT finished_at, checked, issues, stripe_checked FROM billing.reconciliation_run WHERE finished_at IS NOT NULL ORDER BY finished_at DESC LIMIT 1');
        $hooks = DbalValue::int($money['hooks'] ?? 0);

        return new FinanceBreakdownView(
            $plans,
            $this->cohorts($query->from, $query->now),
            DbalValue::int($money['unpaid'] ?? 0),
            DbalValue::int($money['promos'] ?? 0),
            false === $run ? null : ['finished_at' => new \DateTimeImmutable(DbalValue::string($run['finished_at'])), 'checked' => DbalValue::int($run['checked']), 'issues' => DbalValue::int($run['issues']), 'stripe_checked' => true === $run['stripe_checked']],
            DbalValue::int($money['issues'] ?? 0),
            0 === $hooks ? null : DbalValue::int($money['hooks_ok'] ?? 0) / $hooks,
        );
    }

    /** @return list<array{month: string, size: int, retention: array<int, ?float>}> */
    private function cohorts(\DateTimeImmutable $from, \DateTimeImmutable $now): array
    {
        $cohorts = [];
        for ($i = self::COHORTS; $i >= 1; --$i) {
            $start = $from->modify(\sprintf('-%d months', $i));
            $artisans = 'SELECT s.artisan_id FROM billing.subscription_period sp JOIN billing.subscription s ON s.id = sp.subscription_id
                          GROUP BY s.artisan_id HAVING min(sp.period_start) >= :start AND min(sp.period_start) < :end';
            $bounds = ['start' => $start->format(\DATE_ATOM), 'end' => $start->modify('+1 month')->format(\DATE_ATOM)];
            $size = DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM ($artisans) c", $bounds));
            $retention = [];
            foreach (FinanceBreakdownView::RETENTION_MONTHS as $k) {
                $at = $start->modify(\sprintf('+%d months', $k))->modify(self::MID_MONTH);
                $retention[$k] = 0 === $size || $at > $now ? null : DbalValue::int($this->connection->fetchOne(
                    "SELECT count(DISTINCT s.artisan_id) FROM billing.subscription_period sp JOIN billing.subscription s ON s.id = sp.subscription_id
                      WHERE s.artisan_id IN ($artisans) AND sp.period_start <= :at AND sp.period_end > :at",
                    $bounds + ['at' => $at->format(\DATE_ATOM)],
                )) / $size;
            }
            $cohorts[] = ['month' => $start->format('Y-m'), 'size' => $size, 'retention' => $retention];
        }

        return $cohorts;
    }
}
