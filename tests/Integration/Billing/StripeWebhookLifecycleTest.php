<?php

declare(strict_types=1);

namespace App\Tests\Integration\Billing;

use App\Billing\Application\Credit\CreditLedger;
use App\Billing\Application\Credit\QuoteCreditAccount;
use App\Billing\Application\Payment\SyncPlanWithStripe;
use App\Billing\Application\Plan\PlanAction;
use App\Billing\Application\Plan\SavePlan;
use App\Billing\Application\Port\PaymentMethodSummary;
use App\Billing\Application\Port\SubscriptionState;
use App\Billing\Application\Subscription\EnforceBillingGrace;
use App\Billing\Application\Webhook\HandleStripeEvent;
use App\Billing\Application\Webhook\HandleStripeEventHandler;
use App\Billing\Application\Webhook\ProcessedEvents;
use App\Billing\Application\Webhook\StripeEvent;
use App\Billing\Domain\Plan\BillingInterval;
use App\Billing\Domain\Subscription\Subscription;
use App\Billing\Domain\Subscription\SubscriptionId;
use App\Billing\Domain\Subscription\SubscriptionRepository;
use App\Billing\Infrastructure\Payment\InMemoryPaymentGateway;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * T-604a — webhooks Stripe (double en mémoire) : activation au paiement, crédits de période uniques par facture,
 * expiration des crédits non reportés au renouvellement (RG-109), E-40 / E-41, retard, résiliation programmée,
 * annulation ; événement étranger ignoré, rejeu sans effet.
 */
final class StripeWebhookLifecycleTest extends KernelTestCase
{
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a6401';
    private const string PLAN = '0190f5e4-0000-7000-8000-0000000b6401';
    private const string ARTISAN = '0190f5e4-0000-7000-8000-0000000c6401';
    private const string USER = '0190f5e4-0000-7000-8000-0000000d6401';
    private const string SUB = '0190f5e4-0000-7000-8000-0000000e6401';

    private Connection $connection;
    private InMemoryPaymentGateway $gateway;
    private ProcessedEvents $events;
    private HandleStripeEventHandler $handler;
    private string $stripePrice;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        [$connection, $gateway, $events, $handler, $commands, $ledger, $subscriptions] = [$c->get(Connection::class), $c->get(InMemoryPaymentGateway::class), $c->get(ProcessedEvents::class), $c->get(HandleStripeEventHandler::class), $c->get(CommandBus::class), $c->get(CreditLedger::class), $c->get(SubscriptionRepository::class)];
        \assert($connection instanceof Connection && $gateway instanceof InMemoryPaymentGateway && $events instanceof ProcessedEvents && $handler instanceof HandleStripeEventHandler && $commands instanceof CommandBus && $ledger instanceof CreditLedger && $subscriptions instanceof SubscriptionRepository);
        [$this->connection, $this->gateway, $this->events, $this->handler] = [$connection, $gateway, $events, $handler];
        $gateway->reset();
        $connection->executeStatement(
            "INSERT INTO identity.user_account (id, email, email_canonical, password_hash, passwordless, kind, status, first_name, last_name, email_verified_at, password_changed_at, created_at, updated_at) VALUES (:id, 'artisan.webhook@exemple.test', 'artisan.webhook@exemple.test', :hash, false, 'artisan', 'active', 'Artisan', 'Fictif', :at, :at, :at, :at)",
            ['id' => self::USER, 'hash' => '$argon2id$fictif', 'at' => '2026-11-14 10:00:00+00'],
        );
        $ledger->grantFree('0190f5e4-0000-7000-8000-0000000f6401', self::ARTISAN, self::USER, 5, null, new \DateTimeImmutable());
        $commands->dispatch(new SavePlan(self::ADMIN, null, self::PLAN, ['name' => 'Formule Fictive', 'quotes_per_period' => '20', 'monthly_price' => '29,00']));
        $commands->dispatch(new PlanAction(self::ADMIN, self::PLAN, PlanAction::PUBLISH));
        $commands->dispatch(new SyncPlanWithStripe(self::PLAN));
        $this->stripePrice = DbalValue::string($connection->fetchOne('SELECT stripe_price_id FROM billing.plan_price WHERE plan_id = :p AND active', ['p' => self::PLAN]));
        $price = DbalValue::string($connection->fetchOne('SELECT id FROM billing.plan_price WHERE plan_id = :p AND active', ['p' => self::PLAN]));
        $subscriptions->save(Subscription::start(new SubscriptionId(self::SUB), self::ARTISAN, self::PLAN, $price, BillingInterval::Month, new \DateTimeImmutable()));
    }

    private function stripe(string $status, string $start, string $end, bool $cancelAtPeriodEnd = false): void
    {
        $this->gateway->subscriptions['sub_FICTIF'] = new SubscriptionState('sub_FICTIF', $status, $this->stripePrice, new \DateTimeImmutable($start), new \DateTimeImmutable($end), $cancelAtPeriodEnd, ['subscription_id' => self::SUB]);
    }

    /** @param array<string, mixed> $object */
    private function receive(string $id, string $type, array $object): void
    {
        $this->events->record(new StripeEvent($id, $type, false, $object), hash('sha256', $id), new \DateTimeImmutable());
        ($this->handler)(new HandleStripeEvent($id));
    }

    /** @return array<string, mixed> */
    private static function invoice(string $id, string $reason, string $start, string $end): array
    {
        return [
            'id' => $id, 'billing_reason' => $reason, 'amount_paid' => 3480, 'hosted_invoice_url' => 'https://invoice.stripe.test/'.$id,
            'parent' => ['subscription_details' => ['subscription' => 'sub_FICTIF']],
            'lines' => ['data' => [['period' => ['start' => new \DateTimeImmutable($start)->getTimestamp(), 'end' => new \DateTimeImmutable($end)->getTimestamp()]]]],
        ];
    }

    private function value(string $sql): string
    {
        $value = $this->connection->fetchOne($sql, ['s' => self::SUB, 'a' => self::ARTISAN]);

        return \is_bool($value) ? var_export($value, true) : (string) (\is_scalar($value) ? $value : '');
    }

    public function testPaymentActivatesAndGrantsOnce(): void
    {
        $this->stripe('active', '2026-11-14', '2026-12-14');
        $this->receive('evt_1', 'invoice.paid', self::invoice('in_1', 'subscription_create', '2026-11-14', '2026-12-14'));
        self::assertSame('active|sub_FICTIF', $this->value("SELECT status || '|' || stripe_subscription_id FROM billing.subscription WHERE id = :s"));
        self::assertSame('5|20', $this->value("SELECT free || '|' || plan FROM billing.credit_balance WHERE artisan_id = :a"), 'crédit gratuit conservé, forfait crédité');
        self::assertSame('1', $this->value("SELECT count(*) FROM notification.delivery WHERE code = 'E-40' AND channel = 'email'"));

        $this->receive('evt_1', 'invoice.paid', self::invoice('in_1', 'subscription_create', '2026-11-14', '2026-12-14'));
        $this->receive('evt_1bis', 'invoice.paid', self::invoice('in_1', 'subscription_create', '2026-11-14', '2026-12-14'));
        $this->receive('evt_2', 'checkout.session.completed', ['id' => 'cs_FICTIF', 'subscription' => 'sub_FICTIF', 'client_reference_id' => self::SUB]);
        self::assertSame('20|1', $this->value("SELECT plan || '|' || (SELECT count(*) FROM billing.subscription_period WHERE subscription_id = :s) FROM billing.credit_balance WHERE artisan_id = :a"), 'rejeu et renvoi sans double crédit');
        self::assertSame('processed', $this->value("SELECT status FROM billing.processed_stripe_event WHERE event_id = 'evt_2'"));
    }

    public function testRenewalExpiresUnusedPlanCredits(): void
    {
        $this->stripe('active', '2026-11-14', '2026-12-14');
        $this->receive('evt_1', 'invoice.paid', self::invoice('in_1', 'subscription_create', '2026-11-14', '2026-12-14'));
        $this->stripe('active', '2026-12-14', '2027-01-14');
        $this->receive('evt_3', 'invoice.paid', self::invoice('in_2', 'subscription_cycle', '2026-12-14', '2027-01-14'));

        self::assertSame('20', $this->value('SELECT plan FROM billing.credit_balance WHERE artisan_id = :a'), '20 restants expirés, 20 nouveaux');
        self::assertSame('-20', $this->value("SELECT sum(amount) FROM billing.credit_ledger_entry WHERE artisan_id = :a AND type = 'expiry'"));
        self::assertSame('2027-01-14', $this->value("SELECT to_char(current_period_end AT TIME ZONE 'UTC', 'YYYY-MM-DD') FROM billing.subscription WHERE id = :s"));
        self::assertSame('1', $this->value("SELECT count(*) FROM notification.delivery WHERE code = 'E-41'"));
    }

    public function testFailureCancellationScheduleAndEnd(): void
    {
        $this->stripe('active', '2026-11-14', '2026-12-14');
        $this->receive('evt_1', 'invoice.paid', self::invoice('in_1', 'subscription_create', '2026-11-14', '2026-12-14'));

        $this->stripe('active', '2026-11-14', '2026-12-14', cancelAtPeriodEnd: true);
        $this->receive('evt_4', 'customer.subscription.updated', ['id' => 'sub_FICTIF']);
        self::assertSame('active|true', $this->value("SELECT status || '|' || cancel_at_period_end FROM billing.subscription WHERE id = :s"));
        self::assertSame('1', $this->value("SELECT count(*) FROM notification.delivery WHERE code = 'E-43' AND channel = 'email'"), 'résiliation programmée annoncée');

        $this->stripe('past_due', '2026-11-14', '2026-12-14');
        $this->receive('evt_5', 'customer.subscription.updated', ['id' => 'sub_FICTIF']);
        self::assertSame('past_due|false', $this->value("SELECT status || '|' || cancel_at_period_end FROM billing.subscription WHERE id = :s"));

        $this->stripe('canceled', '2026-11-14', '2026-12-14');
        $this->receive('evt_6', 'customer.subscription.deleted', ['id' => 'sub_FICTIF']);
        self::assertSame('cancelled', $this->value('SELECT status FROM billing.subscription WHERE id = :s'));
    }

    public function testPaymentFailureStartsGraceAndRecoveryEndsIt(): void
    {
        $this->stripe('active', '2026-11-14', '2026-12-14');
        $this->receive('evt_1', 'invoice.paid', self::invoice('in_1', 'subscription_create', '2026-11-14', '2026-12-14'));

        $this->stripe('active', '2026-11-14', '2026-12-14');
        $this->receive('evt_f1', 'invoice.payment_failed', ['id' => 'in_2', 'amount_due' => 3480, 'attempt_count' => 1, 'next_payment_attempt' => new \DateTimeImmutable('2026-12-17')->getTimestamp(), 'hosted_invoice_url' => 'https://invoice.stripe.test/in_2', 'parent' => ['subscription_details' => ['subscription' => 'sub_FICTIF']]]);
        self::assertSame('past_due|true', $this->value("SELECT status || '|' || (past_due_since IS NOT NULL) FROM billing.subscription WHERE id = :s"));
        self::assertSame('1', $this->value("SELECT count(*) FROM notification.delivery WHERE code = 'E-44' AND channel = 'email'"));

        $this->stripe('active', '2026-12-14', '2027-01-14');
        $this->receive('evt_f2', 'invoice.paid', self::invoice('in_2', 'subscription_cycle', '2026-12-14', '2027-01-14'));
        self::assertSame('active|false', $this->value("SELECT status || '|' || (past_due_since IS NOT NULL) FROM billing.subscription WHERE id = :s"));
    }

    public function testGraceThenSuspensionThenCancellation(): void
    {
        $this->stripe('active', '2026-11-14', '2026-12-14');
        $this->receive('evt_1', 'invoice.paid', self::invoice('in_1', 'subscription_create', '2026-11-14', '2026-12-14'));
        $this->stripe('past_due', '2026-11-14', '2026-12-14');
        $this->receive('evt_g1', 'customer.subscription.updated', ['id' => 'sub_FICTIF']);
        $commands = self::getContainer()->get(CommandBus::class);
        $ledger = self::getContainer()->get(QuoteCreditAccount::class);
        \assert($commands instanceof CommandBus && $ledger instanceof QuoteCreditAccount);

        $commands->dispatch(new EnforceBillingGrace());
        self::assertSame('past_due', $this->value('SELECT status FROM billing.subscription WHERE id = :s'), 'grâce en cours : envoi encore possible');
        self::assertFalse($ledger->sendingSuspended(self::ARTISAN));

        $this->connection->executeStatement("UPDATE billing.subscription SET past_due_since = now() - interval '8 days' WHERE id = :s", ['s' => self::SUB]);
        $commands->dispatch(new EnforceBillingGrace());
        $commands->dispatch(new EnforceBillingGrace());
        self::assertSame('suspended', $this->value('SELECT status FROM billing.subscription WHERE id = :s'));
        self::assertTrue($ledger->sendingSuspended(self::ARTISAN));
        self::assertSame('1', $this->value("SELECT count(*) FROM notification.delivery WHERE code = 'E-45' AND channel = 'email'"), 'E-45 une seule fois');

        $this->connection->executeStatement("UPDATE billing.subscription SET past_due_since = now() - interval '31 days' WHERE id = :s", ['s' => self::SUB]);
        $commands->dispatch(new EnforceBillingGrace());
        self::assertContains('subscription_cancel', array_column($this->gateway->calls, 0), 'impayé de plus de 30 jours : annulé chez Stripe');
        $this->stripe('canceled', '2026-11-14', '2026-12-14');
        $this->receive('evt_g2', 'customer.subscription.deleted', ['id' => 'sub_FICTIF']);
        self::assertSame('cancelled', $this->value('SELECT status FROM billing.subscription WHERE id = :s'));
        self::assertFalse($ledger->sendingSuspended(self::ARTISAN));
    }

    public function testUpgradeAddsTheDifferenceOnce(): void
    {
        $commands = self::getContainer()->get(CommandBus::class);
        \assert($commands instanceof CommandBus);
        $upper = '0190f5e4-0000-7000-8000-0000000b6402';
        $commands->dispatch(new SavePlan(self::ADMIN, null, $upper, ['name' => 'Formule Supérieure Fictive', 'quotes_per_period' => '50', 'monthly_price' => '59,00']));
        $commands->dispatch(new PlanAction(self::ADMIN, $upper, PlanAction::PUBLISH));
        $commands->dispatch(new SyncPlanWithStripe($upper));
        $this->stripe('active', '2026-11-14', '2026-12-14');
        $this->receive('evt_1', 'invoice.paid', self::invoice('in_1', 'subscription_create', '2026-11-14', '2026-12-14'));

        $this->stripePrice = DbalValue::string($this->connection->fetchOne('SELECT stripe_price_id FROM billing.plan_price WHERE plan_id = :p AND active', ['p' => $upper]));
        $this->stripe('active', '2026-11-14', '2026-12-14');
        $this->receive('evt_u1', 'customer.subscription.updated', ['id' => 'sub_FICTIF']);
        $this->receive('evt_u2', 'customer.subscription.updated', ['id' => 'sub_FICTIF']);

        self::assertSame($upper, $this->value('SELECT plan_id FROM billing.subscription WHERE id = :s'));
        self::assertSame('50', $this->value('SELECT plan FROM billing.credit_balance WHERE artisan_id = :a'), 'Q7 : 20 + (50 − 20), une seule fois');
        self::assertSame('1', $this->value("SELECT count(*) FROM notification.delivery WHERE code = 'E-43' AND channel = 'email'"));
    }

    public function testDisputeIsLoggedForFinance(): void
    {
        $this->receive('evt_d1', 'charge.dispute.created', ['id' => 'dp_FICTIF', 'charge' => 'ch_FICTIF', 'amount' => 3480, 'reason' => 'fraudulent']);
        self::assertSame('1', $this->value("SELECT count(*) FROM admin.audit_log WHERE action = 'billing.dispute_opened'"));
    }

    public function testInvoicesAreReplicatedAndArchivedOnce(): void
    {
        $this->connection->executeStatement("UPDATE billing.credit_account SET stripe_customer_id = 'cus_FICTIF' WHERE artisan_id = :a", ['a' => self::ARTISAN]);
        $pdf = 'https://pay.stripe.com/invoice/fictif/pdf';
        $this->gateway->pdfs[$pdf] = '%PDF-1.4 facture fictive';
        $this->stripe('active', '2026-11-14', '2026-12-14');
        $invoice = self::invoice('in_1', 'subscription_create', '2026-11-14', '2026-12-14') + ['customer' => 'cus_FICTIF', 'status' => 'paid', 'number' => 'TM-FICTIF-0001', 'currency' => 'eur', 'total' => 3480, 'total_excluding_tax' => 2900, 'created' => 1794700000, 'invoice_pdf' => $pdf];
        $this->receive('evt_i1', 'invoice.paid', $invoice);
        $this->receive('evt_i2', 'invoice.updated', $invoice);

        self::assertSame('1|TM-FICTIF-0001|paid|2900|580|3480|true', $this->value("SELECT count(*) || '|' || min(number) || '|' || min(status) || '|' || min(amount_ht_cents) || '|' || min(vat_cents) || '|' || min(amount_ttc_cents) || '|' || bool_and(pdf_key IS NOT NULL) FROM billing.invoice WHERE artisan_id = :a"));
        self::assertSame(hash('sha256', '%PDF-1.4 facture fictive'), $this->value('SELECT pdf_sha256 FROM billing.invoice WHERE artisan_id = :a'));

        $this->gateway->paymentMethods['cus_FICTIF'] = new PaymentMethodSummary('card', 'visa', '4242', 12, 2030);
        $this->receive('evt_pm', 'payment_method.attached', ['id' => 'pm_FICTIF', 'customer' => 'cus_FICTIF']);
        self::assertSame('card|visa|4242|12|2030', $this->value("SELECT type || '|' || brand || '|' || last4 || '|' || exp_month || '|' || exp_year FROM billing.payment_method WHERE artisan_id = :a"));

        $this->receive('evt_i3', 'invoice.finalized', ['id' => 'in_AUTRE', 'customer' => 'cus_AUTRE', 'status' => 'open', 'total' => 100]);
        self::assertSame('ignored', $this->value("SELECT status FROM billing.processed_stripe_event WHERE event_id = 'evt_i3'"));
    }

    public function testForeignEventsAreIgnored(): void
    {
        $this->gateway->subscriptions['sub_AUTRE'] = new SubscriptionState('sub_AUTRE', 'active', null, null, null, false, []);
        $this->receive('evt_7', 'customer.subscription.updated', ['id' => 'sub_AUTRE']);
        $this->receive('evt_8', 'customer.created', ['id' => 'cus_X']);
        self::assertSame('ignored|ignored', $this->value("SELECT string_agg(status, '|' ORDER BY event_id) FROM billing.processed_stripe_event WHERE event_id IN ('evt_7', 'evt_8')"));
        self::assertSame('incomplete', $this->value('SELECT status FROM billing.subscription WHERE id = :s'));
    }
}
