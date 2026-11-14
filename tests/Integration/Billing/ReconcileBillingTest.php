<?php

declare(strict_types=1);

namespace App\Tests\Integration\Billing;

use App\Billing\Application\Credit\CreditLedger;
use App\Billing\Application\Port\InvoiceSnapshot;
use App\Billing\Application\Port\SubscriptionState;
use App\Billing\Application\Reconciliation\ReconcileBilling;
use App\Billing\Application\Reconciliation\ReconciliationIssue;
use App\Billing\Application\Reconciliation\ReconciliationStore;
use App\Billing\Application\Reconciliation\ResolveReconciliationIssue;
use App\Billing\Infrastructure\Payment\InMemoryPaymentGateway;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * T-607 (ADR-0010, ADR-0006) : rapprochement Stripe ↔ base et soldes du registre ; écarts ouverts une seule fois,
 * résolus seuls quand ils disparaissent, ou à la main ; contrôles locaux seulement sans clé Stripe.
 */
final class ReconcileBillingTest extends KernelTestCase
{
    private const string ARTISAN = '0190f5e4-0000-7000-8000-0000000c6607';
    private const string USER = '0190f5e4-0000-7000-8000-0000000d6617';
    private const string PLAN = '0190f5e4-0000-7000-8000-0000000b6617';
    private const string PRICE = '0190f5e4-0000-7000-8000-0000000b6618';

    private Connection $connection;
    private InMemoryPaymentGateway $gateway;
    private CommandBus $commands;
    private ReconciliationStore $store;
    private \DateTimeImmutable $periodEnd;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        [$connection, $gateway, $commands, $store, $ledger] = [$c->get(Connection::class), $c->get(InMemoryPaymentGateway::class), $c->get(CommandBus::class), $c->get(ReconciliationStore::class), $c->get(CreditLedger::class)];
        \assert($connection instanceof Connection && $gateway instanceof InMemoryPaymentGateway && $commands instanceof CommandBus && $store instanceof ReconciliationStore && $ledger instanceof CreditLedger);
        [$this->connection, $this->gateway, $this->commands, $this->store] = [$connection, $gateway, $commands, $store];
        $gateway->reset();
        $this->periodEnd = new \DateTimeImmutable('+20 days')->setTime(10, 0);
        $ledger->grantFree('0190f5e4-0000-7000-8000-0000000f6617', self::ARTISAN, self::USER, 5, null, new \DateTimeImmutable());
        $connection->executeStatement("UPDATE billing.credit_account SET stripe_customer_id = 'cus_R' WHERE artisan_id = :a", ['a' => self::ARTISAN]);
        $connection->executeStatement("INSERT INTO billing.plan (id, slug, name, visibility, status, position, created_at, updated_at) VALUES (:p, 'formule-rappro-fictive', 'Formule Rappro Fictive', 'public', 'published', 9, now(), now())", ['p' => self::PLAN]);
        $connection->executeStatement("INSERT INTO billing.plan_price (id, plan_id, interval, amount_cents, currency, active, created_at) VALUES (:id, :p, 'month', 2900, 'EUR', true, now())", ['id' => self::PRICE, 'p' => self::PLAN]);
        $connection->executeStatement(
            "INSERT INTO billing.subscription (id, artisan_id, plan_id, plan_price_id, interval, status, stripe_subscription_id, current_period_start, current_period_end, created_at, updated_at) VALUES ('0190f5e4-0000-7000-8000-0000000e6617', :a, :p, :price, 'month', 'active', 'sub_R', now() - interval '10 days', :end, now(), now())",
            ['a' => self::ARTISAN, 'p' => self::PLAN, 'price' => self::PRICE, 'end' => $this->periodEnd->format(\DATE_ATOM)],
        );
        $connection->executeStatement(
            "INSERT INTO billing.invoice (id, artisan_id, stripe_invoice_id, number, status, currency, amount_ht_cents, vat_cents, amount_ttc_cents, amount_paid_cents, issued_at, updated_at) VALUES ('0190f5e4-0000-7000-8000-00000001d617', :a, 'in_R', 'TM-R-1', 'paid', 'EUR', 2900, 580, 3480, 3480, now() - interval '10 days', now())",
            ['a' => self::ARTISAN],
        );
        $connection->executeStatement("INSERT INTO billing.subscription_period (id, subscription_id, stripe_invoice_id, period_start, period_end, credits, amount_paid_cents, created_at) VALUES ('0190f5e4-0000-7000-8000-00000001e617', '0190f5e4-0000-7000-8000-0000000e6617', 'in_R', now() - interval '10 days', :end, 20, 3480, now())", ['end' => $this->periodEnd->format(\DATE_ATOM)]);
        $this->stripeState('active');
        $this->gateway->invoices = [new InvoiceSnapshot('in_R', 'cus_R', 'paid', 3480, 'subscription_create', 'sub_R')];
    }

    private function stripeState(string $status): void
    {
        $this->gateway->subscriptions['sub_R'] = new SubscriptionState('sub_R', $status, 'price_R', new \DateTimeImmutable('-10 days'), $this->periodEnd, false, ['subscription_id' => '0190f5e4-0000-7000-8000-0000000e6617']);
    }

    /** @return list<string> types d'écarts ouverts, triés */
    private function open(): array
    {
        $kinds = array_map(static fn (ReconciliationIssue $i): string => $i->kind, $this->store->openIssues());
        sort($kinds);

        return $kinds;
    }

    public function testConsistentDataGivesNoIssue(): void
    {
        $this->commands->dispatch(new ReconcileBilling());

        self::assertSame([], $this->open());
        $run = $this->store->lastRun();
        self::assertNotNull($run);
        self::assertTrue($run->stripeChecked);
        self::assertSame(0, $run->issues);
        self::assertGreaterThanOrEqual(3, $run->checked);
    }

    public function testDiscrepanciesAreRaisedOnceThenAutoResolved(): void
    {
        $this->connection->executeStatement('UPDATE billing.credit_balance SET free = 7 WHERE artisan_id = :a', ['a' => self::ARTISAN]);
        $this->stripeState('past_due');
        $this->gateway->invoices[] = new InvoiceSnapshot('in_ABSENTE', 'cus_R', 'paid', 3480, 'subscription_cycle', 'sub_R');
        $this->gateway->invoices[] = new InvoiceSnapshot('in_ETRANGERE', 'cus_AUTRE', 'paid', 100, null, null);
        $this->connection->executeStatement('DELETE FROM billing.subscription_period');
        $this->connection->executeStatement("INSERT INTO billing.processed_stripe_event (event_id, type, livemode, payload, payload_sha256, received_at) VALUES ('evt_BLOQUE', 'invoice.paid', false, '{}', repeat('0', 64), now() - interval '2 hours')");

        $this->commands->dispatch(new ReconcileBilling());
        $this->commands->dispatch(new ReconcileBilling());
        self::assertSame(['credits_not_granted', 'invoice_missing', 'ledger_balance', 'subscription_status', 'webhook_backlog'], $this->open(), 'une seule fois chacun, facture d’un client étranger ignorée');

        $this->connection->executeStatement('UPDATE billing.credit_balance SET free = 5 WHERE artisan_id = :a', ['a' => self::ARTISAN]);
        $this->stripeState('active');
        $this->commands->dispatch(new ReconcileBilling());
        self::assertSame(['credits_not_granted', 'invoice_missing', 'webhook_backlog'], $this->open(), 'corrigés : résolus automatiquement');

        $backlog = array_values(array_filter($this->store->openIssues(), static fn (ReconciliationIssue $i): bool => 'webhook_backlog' === $i->kind))[0];
        $this->commands->dispatch(new ResolveReconciliationIssue('0190f5e4-0000-7000-8000-0000000ab817', $backlog->id));
        self::assertSame(1, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM admin.audit_log WHERE action = 'billing.reconciliation_resolved'")));
    }

    public function testMissingStripeSubscriptionAndNoKey(): void
    {
        unset($this->gateway->subscriptions['sub_R']);
        $this->commands->dispatch(new ReconcileBilling());
        self::assertSame(['subscription_missing_stripe'], $this->open());

        // Sans clé : seuls les contrôles locaux tournent, les écarts Stripe restent ouverts.
        $this->gateway->configured = false;
        $this->connection->executeStatement('UPDATE billing.credit_balance SET free = 9 WHERE artisan_id = :a', ['a' => self::ARTISAN]);
        $this->commands->dispatch(new ReconcileBilling());
        self::assertSame(['ledger_balance', 'subscription_missing_stripe'], $this->open());
        self::assertFalse($this->store->lastRun()?->stripeChecked);
    }
}
