<?php

declare(strict_types=1);

namespace App\Tests\Integration\Billing;

use App\Billing\Infrastructure\Admin\BillingDashboardMetrics;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** T-805a — BO01.1 : MRR (annuel au douzième, montant HT), abonnés actifs, nouveaux abonnés, churn, ARPU. */
final class BillingDashboardMetricsTest extends KernelTestCase
{
    private const string PLAN = '0190f5e4-0000-7000-8000-0000000b8051';

    public function testSubscriptionMetricsComeFromBilledPeriods(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $connection->executeStatement("INSERT INTO billing.plan (id, slug, name, visibility, status, position, created_at, updated_at) VALUES (:p, 'formule-bo01-fictive', 'Formule BO01 Fictive', 'public', 'published', 9, now(), now())", ['p' => self::PLAN]);
        // Mensuel en cours (29 € HT), annuel en cours (240 € HT → 20 €/mois), mensuel résilié pendant la période.
        $this->subscription($connection, '1', 'month', 'active', '-10 days', '+20 days', 2900);
        $this->subscription($connection, '2', 'year', 'active', '-5 days', '+360 days', 24000);
        $this->subscription($connection, '3', 'month', 'cancelled', '-40 days', '-12 days', 2900);

        $metrics = self::getContainer()->get(BillingDashboardMetrics::class);
        \assert($metrics instanceof BillingDashboardMetrics);
        $values = $metrics->metrics(new \DateTimeImmutable('-15 days'), new \DateTimeImmutable('+1 minute'));

        self::assertSame(4900, $values['mrr_cents']);
        self::assertSame(2, $values['active_subscribers']);
        self::assertSame(2, $values['new_subscribers'], 'premiers abonnements démarrés sur la période');
        self::assertEqualsWithDelta(1.0, $values['churn_rate'], 0.001, '1 résilié / 1 actif au début');
        self::assertEqualsWithDelta(2450.0, $values['arpu_cents'], 0.001);
    }

    private function subscription(Connection $c, string $n, string $interval, string $status, string $start, string $end, int $ht): void
    {
        $sub = '0190f5e4-0000-7000-8000-0000000e805'.$n;
        $artisan = '0190f5e4-0000-7000-8000-0000000c805'.$n;
        $price = '0190f5e4-0000-7000-8000-0000000b805'.(string) (4 + (int) $n);
        $c->executeStatement('INSERT INTO billing.credit_account (artisan_id, user_id, created_at) VALUES (:a, :u, now())', ['a' => $artisan, 'u' => '0190f5e4-0000-7000-8000-0000000d805'.$n]);
        $c->executeStatement("INSERT INTO billing.plan_price (id, plan_id, interval, amount_cents, currency, active, created_at) VALUES (:id, :p, :i, :a, 'EUR', :active, now())", ['id' => $price, 'p' => self::PLAN, 'i' => $interval, 'a' => $ht, 'active' => 'cancelled' === $status ? 'false' : 'true']);
        $c->executeStatement(
            "INSERT INTO billing.subscription (id, artisan_id, plan_id, plan_price_id, interval, status, stripe_subscription_id, current_period_start, current_period_end, created_at, updated_at)
             VALUES (:id, :a, :p, :price, :i, :s, :stripe, now() + CAST(:start AS interval), now() + CAST(:end AS interval), now() + CAST(:start AS interval), date_trunc('second', now()))",
            ['id' => $sub, 'a' => $artisan, 'p' => self::PLAN, 'price' => $price, 'i' => $interval, 's' => $status, 'stripe' => 'sub_bo01_'.$n, 'start' => $start, 'end' => $end],
        );
        $c->executeStatement(
            "INSERT INTO billing.invoice (id, artisan_id, stripe_invoice_id, number, status, currency, amount_ht_cents, vat_cents, amount_ttc_cents, amount_paid_cents, issued_at, updated_at)
             VALUES (:id, :a, :inv, :num, 'paid', 'EUR', :ht, :vat, :ttc, :ttc, now(), now())",
            ['id' => '0190f5e4-0000-7000-8000-00000001d80'.$n, 'a' => $artisan, 'inv' => 'in_bo01_'.$n, 'num' => 'TM-BO01-'.$n, 'ht' => $ht, 'vat' => intdiv($ht, 5), 'ttc' => $ht + intdiv($ht, 5)],
        );
        $c->executeStatement(
            'INSERT INTO billing.subscription_period (id, subscription_id, stripe_invoice_id, period_start, period_end, credits, amount_paid_cents, created_at)
             VALUES (:id, :sub, :inv, now() + CAST(:start AS interval), now() + CAST(:end AS interval), 20, :ttc, now())',
            ['id' => '0190f5e4-0000-7000-8000-00000001e80'.$n, 'sub' => $sub, 'inv' => 'in_bo01_'.$n, 'start' => $start, 'end' => $end, 'ttc' => $ht + intdiv($ht, 5)],
        );
    }
}
