<?php

declare(strict_types=1);

namespace App\Tests\Integration\Billing;

use App\Billing\Application\Payment\StripeCustomers;
use App\Billing\Application\Payment\SyncPlanWithStripe;
use App\Billing\Application\Plan\PlanAction;
use App\Billing\Application\Plan\SavePlan;
use App\Billing\Infrastructure\Payment\DbalStripeLinks;
use App\Billing\Infrastructure\Payment\InMemoryPaymentGateway;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Domain\Time\Clock;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * T-602 — catalogue Stripe aligné sur BO08 (double en mémoire, aucun appel réseau) : brouillon absent de Stripe,
 * produit et prix créés à la publication, nouveau prix et ancien retiré au changement de montant, reprise sans
 * doublon ; un seul client Stripe par artisan.
 */
final class StripeCatalogSyncTest extends KernelTestCase
{
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a6201';
    private const string PLAN = '0190f5e4-0000-7000-8000-0000000b6201';
    private const string ARTISAN = '0190f5e4-0000-7000-8000-0000000c6201';
    private const string USER = '0190f5e4-0000-7000-8000-0000000d6201';
    private const array INPUT = ['name' => 'Forfait Fictif', 'quotes_per_period' => '12', 'monthly_price' => '19,00'];

    private CommandBus $commands;
    private Connection $connection;
    private InMemoryPaymentGateway $gateway;

    protected function setUp(): void
    {
        self::bootKernel();
        $commands = self::getContainer()->get(CommandBus::class);
        $connection = self::getContainer()->get(Connection::class);
        $gateway = self::getContainer()->get(InMemoryPaymentGateway::class);
        \assert($commands instanceof CommandBus && $connection instanceof Connection && $gateway instanceof InMemoryPaymentGateway);
        [$this->commands, $this->connection, $this->gateway] = [$commands, $connection, $gateway];
        $this->gateway->reset();
    }

    public function testCatalogFollowsPlanLifecycle(): void
    {
        $this->commands->dispatch(new SavePlan(self::ADMIN, null, self::PLAN, self::INPUT));
        self::assertSame(1, $this->outbox(), 'PlanChanged publié dans l’outbox');
        $this->sync();
        self::assertSame([], $this->gateway->calls, 'brouillon : rien chez Stripe');

        $this->commands->dispatch(new PlanAction(self::ADMIN, self::PLAN, PlanAction::PUBLISH));
        $this->sync();
        self::assertSame(['prod', 'price'], array_column($this->gateway->calls, 0));
        $productId = DbalValue::string($this->connection->fetchOne('SELECT stripe_product_id FROM billing.plan WHERE id = :p', ['p' => self::PLAN]));
        self::assertStringStartsWith('prod_', $productId);
        self::assertSame(1, $this->prices('stripe_price_id IS NOT NULL AND active'));

        $this->sync();
        self::assertCount(3, $this->gateway->calls, 'reprise : produit remis à jour, aucun prix en double');

        $this->commands->dispatch(new SavePlan(self::ADMIN, self::PLAN, '0190f5e4-0000-7000-8000-0000000b6202', ['monthly_price' => '24,00'] + self::INPUT));
        $this->gateway->calls = [];
        $this->sync();
        self::assertSame(['price', 'price_off'], array_column($this->gateway->calls, 0), 'produit inchangé ; nouveau prix, ancien retiré de la vente');
        self::assertSame(2400, $this->gateway->calls[0][1]['amount'] ?? null);
        self::assertSame(1, $this->prices('NOT active AND stripe_retired_at IS NOT NULL'));

        $this->commands->dispatch(new PlanAction(self::ADMIN, self::PLAN, PlanAction::ARCHIVE));
        $this->gateway->calls = [];
        $this->sync();
        self::assertSame([['prod', false]], array_map(static fn (array $c): array => [$c[0], $c[1]['active'] ?? null], $this->gateway->calls), 'archivé : produit désactivé');
    }

    public function testWithoutKeyNothingIsSent(): void
    {
        $this->commands->dispatch(new SavePlan(self::ADMIN, null, self::PLAN, self::INPUT));
        $this->commands->dispatch(new PlanAction(self::ADMIN, self::PLAN, PlanAction::PUBLISH));
        $this->gateway->configured = false;
        $this->sync();
        self::assertSame([], $this->gateway->calls);
        self::assertNull($this->connection->fetchOne('SELECT stripe_product_id FROM billing.plan WHERE id = :p', ['p' => self::PLAN]));
    }

    public function testOneStripeCustomerPerArtisan(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO identity.user_account (id, email, email_canonical, password_hash, passwordless, kind, status, first_name, last_name, email_verified_at, password_changed_at, created_at, updated_at) VALUES (:id, 'artisan.fictif@exemple.test', 'artisan.fictif@exemple.test', :hash, false, 'artisan', 'active', 'Artisan', 'Fictif', :at, :at, :at, :at)",
            ['id' => self::USER, 'hash' => '$argon2id$fictif', 'at' => '2026-11-14 10:00:00+00'],
        );
        // Sans consommateur avant le tunnel d'abonnement (T-603) : assemblé ici avec les services réels.
        $links = self::getContainer()->get(DbalStripeLinks::class);
        $queries = self::getContainer()->get(QueryBus::class);
        $clock = self::getContainer()->get(Clock::class);
        \assert($links instanceof DbalStripeLinks && $queries instanceof QueryBus && $clock instanceof Clock);
        $customers = new StripeCustomers($this->gateway, $links, $queries, $clock);

        $first = $customers->idFor(self::ARTISAN, self::USER);
        $second = $customers->idFor(self::ARTISAN, self::USER);

        self::assertSame($first, $second);
        self::assertSame([['cus', 'Artisan Fictif']], array_map(static fn (array $c): array => [$c[0], $c[1]['name'] ?? null], $this->gateway->calls));
        self::assertSame($first, $this->connection->fetchOne('SELECT stripe_customer_id FROM billing.credit_account WHERE artisan_id = :a', ['a' => self::ARTISAN]));
    }

    private function sync(): void
    {
        $this->commands->dispatch(new SyncPlanWithStripe(self::PLAN));
    }

    private function outbox(): int
    {
        return DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM shared.outbox_message WHERE message_type LIKE '%PlanChanged' AND body LIKE '%".self::PLAN."%'"));
    }

    private function prices(string $where): int
    {
        return DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM billing.plan_price WHERE plan_id = :p AND '.$where, ['p' => self::PLAN]));
    }
}
