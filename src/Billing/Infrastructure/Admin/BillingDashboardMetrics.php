<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Admin;

use App\Shared\Application\Admin\DashboardMetricSource;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/**
 * BO01.1 — indicateurs d'abonnement calculés sur les périodes facturées (`billing.subscription_period`), ce qui permet
 * la comparaison N-1 :
 * - MRR et abonnés actifs à la fin de la période : périodes en cours à cet instant, montant HT de la facture (à défaut
 *   le montant payé), un abonnement annuel compté pour un douzième ;
 * - nouveaux abonnés : premier abonnement payé démarré sur la période ;
 * - churn : abonnements résiliés sur la période / abonnés actifs au début ;
 * - conversion gratuit → payant : artisans vérifiés avant la période, jamais abonnés jusque-là, abonnés pendant ;
 * - artisans à 0 crédit non abonnés : état actuel (aucun historique des soldes).
 */
final readonly class BillingDashboardMetrics implements DashboardMetricSource
{
    private const int MONTHS_PER_YEAR = 12;

    public function __construct(private Connection $connection)
    {
    }

    public function metrics(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $period = ['from' => $from->format(\DATE_ATOM), 'to' => $to->format(\DATE_ATOM)];
        [$mrr, $active] = $this->snapshot($to->modify('-1 second'));
        [, $activeAtStart] = $this->snapshot($from);
        $cancelled = DbalValue::int($this->connection->fetchOne(
            "SELECT count(*) FROM billing.subscription WHERE status = 'cancelled' AND updated_at >= :from AND updated_at < :to",
            $period,
        ));
        $firstPaid = 'SELECT s.artisan_id, min(sp.period_start) AS first_start FROM billing.subscription_period sp JOIN billing.subscription s ON s.id = sp.subscription_id GROUP BY s.artisan_id';
        $newSubscribers = DbalValue::int($this->connection->fetchOne(
            "SELECT count(*) FROM ($firstPaid) f WHERE f.first_start >= :from AND f.first_start < :to",
            $period,
        ));
        $conversion = $this->connection->fetchAssociative(
            "SELECT count(*) AS eligible, count(*) FILTER (WHERE f.first_start >= :from AND f.first_start < :to) AS converted
               FROM artisan.artisan_profile a
               LEFT JOIN ($firstPaid) f ON f.artisan_id = a.id
              WHERE a.verified_at < :from AND (f.first_start IS NULL OR f.first_start >= :from)",
            $period,
        ) ?: [];
        $eligible = DbalValue::int($conversion['eligible'] ?? 0);
        $zeroCredit = DbalValue::int($this->connection->fetchOne(
            "SELECT count(*) FROM artisan.artisan_profile a
               LEFT JOIN billing.credit_balance b ON b.artisan_id = a.id
              WHERE a.status = 'verified' AND a.hidden_at IS NULL
                AND coalesce(b.free, 0) + coalesce(b.plan, 0) + coalesce(b.extra, 0) = 0
                AND NOT EXISTS (SELECT 1 FROM billing.subscription s WHERE s.artisan_id = a.id AND s.status IN ('active', 'past_due'))",
        ));

        return [
            'mrr_cents' => $mrr,
            'active_subscribers' => $active,
            'new_subscribers' => $newSubscribers,
            'churn_rate' => 0 === $activeAtStart ? null : $cancelled / $activeAtStart,
            'arpu_cents' => 0 === $active ? null : $mrr / $active,
            'free_to_paid_rate' => 0 === $eligible ? null : DbalValue::int($conversion['converted'] ?? 0) / $eligible,
            'zero_credit_unsubscribed' => $zeroCredit,
        ];
    }

    /** @return array{0: int, 1: int} MRR en centimes HT, nombre d'abonnements en cours à l'instant donné */
    private function snapshot(\DateTimeImmutable $at): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT coalesce(sum(CASE WHEN s.interval = 'year' THEN coalesce(i.amount_ht_cents, sp.amount_paid_cents)::numeric / :months
                                      ELSE coalesce(i.amount_ht_cents, sp.amount_paid_cents) END), 0) AS mrr,
                    count(DISTINCT sp.subscription_id) AS active
               FROM billing.subscription_period sp
               JOIN billing.subscription s ON s.id = sp.subscription_id
               LEFT JOIN billing.invoice i ON i.stripe_invoice_id = sp.stripe_invoice_id
              WHERE sp.period_start <= :at AND sp.period_end > :at",
            ['at' => $at->format(\DATE_ATOM), 'months' => self::MONTHS_PER_YEAR],
        ) ?: [];

        return [(int) round(DbalValue::nullableFloat($row['mrr'] ?? 0) ?? 0.0), DbalValue::int($row['active'] ?? 0)];
    }
}
