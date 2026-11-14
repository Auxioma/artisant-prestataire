<?php

declare(strict_types=1);

namespace App\Tests\Integration\Billing;

use App\Billing\Application\Credit\CreditLedger;
use App\Billing\Application\Payment\SyncPlanWithStripe;
use App\Billing\Application\Plan\PlanAction;
use App\Billing\Application\Plan\SavePlan;
use App\Billing\Application\Port\SubscriptionState;
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

        $this->stripe('past_due', '2026-11-14', '2026-12-14');
        $this->receive('evt_5', 'customer.subscription.updated', ['id' => 'sub_FICTIF']);
        self::assertSame('past_due|false', $this->value("SELECT status || '|' || cancel_at_period_end FROM billing.subscription WHERE id = :s"));

        $this->stripe('canceled', '2026-11-14', '2026-12-14');
        $this->receive('evt_6', 'customer.subscription.deleted', ['id' => 'sub_FICTIF']);
        self::assertSame('cancelled', $this->value('SELECT status FROM billing.subscription WHERE id = :s'));
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
