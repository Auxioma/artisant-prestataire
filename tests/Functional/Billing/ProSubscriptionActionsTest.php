<?php

declare(strict_types=1);

namespace App\Tests\Functional\Billing;

use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Billing\Application\Credit\CreditLedger;
use App\Billing\Application\Payment\SyncPlanWithStripe;
use App\Billing\Application\Plan\PlanAction;
use App\Billing\Application\Plan\SavePlan;
use App\Billing\Infrastructure\Payment\InMemoryPaymentGateway;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * PR11.3 / §4.3 : montée immédiate (prorata, crédits Q7 annoncés), descente programmée à l'échéance, passage à
 * l'annuel, résiliation avec motif, annulation de la résiliation ; demandes transmises à Stripe (double en mémoire).
 */
final class ProSubscriptionActionsTest extends WebTestCase
{
    use ArtisanDossier;

    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000aa605';
    private const string BASIC = '0190f5e4-0000-7000-8000-0000000b6605';
    private const string UPPER = '0190f5e4-0000-7000-8000-0000000b6606';
    private const string SUB = '0190f5e4-0000-7000-8000-0000000e6605';

    private KernelBrowser $client;
    private Connection $connection;
    private InMemoryPaymentGateway $gateway;
    private string $artisan;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        $gateway = self::getContainer()->get(InMemoryPaymentGateway::class);
        \assert($connection instanceof Connection && $gateway instanceof InMemoryPaymentGateway);
        [$this->connection, $this->gateway] = [$connection, $gateway];
        $gateway->reset();
        $this->prepareReferentials();
        $this->artisan = $this->submittedDossier(200);
        $this->uploadPiece('kbis');
        $this->uploadPiece('insurance_rc');
        $this->dossierDispatch(new ApproveArtisan($this->artisan, self::ADMIN));
        $connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id = :a", ['a' => self::DOSSIER_USER]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $ledger = self::getContainer()->get(CreditLedger::class);
        \assert($ledger instanceof CreditLedger);
        $ledger->grantFree('0190f5e4-0000-7000-8000-0000000fa605', $this->artisan, self::DOSSIER_USER, 5, null, new \DateTimeImmutable());
        foreach ([[self::BASIC, 'Formule Fictive', '20', '29,00', '290,00'], [self::UPPER, 'Formule Supérieure Fictive', '50', '59,00', '']] as [$id, $name, $volume, $monthly, $yearly]) {
            $this->dossierDispatch(new SavePlan(self::ADMIN, null, $id, ['name' => $name, 'quotes_per_period' => $volume, 'monthly_price' => $monthly, 'yearly_price' => $yearly]));
            $this->dossierDispatch(new PlanAction(self::ADMIN, $id, PlanAction::PUBLISH));
            $this->dossierDispatch(new SyncPlanWithStripe($id));
        }
        $this->gateway->calls = [];

        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->request('POST', '/connexion', ['_username' => 'dominique.verif@exemple.test', '_password' => 'cheval agrafe batterie', '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value')]);
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    private function subscribe(string $planId, string $status = 'active'): void
    {
        $price = $this->connection->fetchOne("SELECT id FROM billing.plan_price WHERE plan_id = :p AND active AND interval = 'month'", ['p' => $planId]);
        $this->connection->executeStatement(
            "INSERT INTO billing.subscription (id, artisan_id, plan_id, plan_price_id, interval, status, stripe_subscription_id, current_period_start, current_period_end, past_due_since, created_at, updated_at) VALUES (:id, :a, :p, :price, 'month', :status, 'sub_FICTIF_ACT', now() - interval '10 days', now() + interval '20 days', CASE WHEN :status = 'past_due' THEN now() ELSE NULL END, now(), now())",
            ['id' => self::SUB, 'a' => $this->artisan, 'p' => $planId, 'price' => $price, 'status' => $status],
        );
    }

    /** @return list<string> */
    private function calls(): array
    {
        return array_column($this->gateway->calls, 0);
    }

    public function testUpgradeIsImmediate(): void
    {
        $this->subscribe(self::BASIC);
        $page = $this->client->request('GET', '/espace-pro/abonnement');
        self::assertStringContainsString('Passer en annuel', $page->filter('.tm-psub__manage')->text());
        $choose = $page->filter('.tm-psub__offer:not(.is-current) a')->attr('href');
        self::assertStringContainsString('/espace-pro/abonnement/changer', (string) $choose);

        $preview = $this->client->request('GET', (string) $choose);
        $recap = $preview->filter('.tm-subscribe__recap')->text();
        self::assertStringContainsString('Immédiate', $recap);
        self::assertStringContainsString('30 devis ajoutés', $recap, 'Q7 : 50 − 20');
        $this->client->submit($preview->selectButton('Confirmer le changement')->form());
        self::assertResponseRedirects('/espace-pro/abonnement');
        self::assertSame(['subscription_price'], $this->calls());
        self::assertSame('create_prorations', $this->gateway->calls[0][1]['proration'] ?? null);
        self::assertSame(self::BASIC, $this->connection->fetchOne('SELECT plan_id FROM billing.subscription WHERE id = :s', ['s' => self::SUB]), 'le webhook fera le changement');
        self::assertSame(1, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM admin.audit_log WHERE action = 'billing.plan_change_requested'")));
    }

    public function testDowngradeIsScheduledAtRenewal(): void
    {
        $this->subscribe(self::UPPER);
        $preview = $this->client->request('GET', '/espace-pro/abonnement/changer?forfait='.self::BASIC.'&periode=mois');
        self::assertStringNotContainsString('Immédiate', $preview->filter('.tm-subscribe__recap')->text());
        $this->client->submit($preview->selectButton('Confirmer le changement')->form());
        self::assertSame(['subscription_schedule'], $this->calls());
        self::assertNotFalse($this->connection->fetchOne('SELECT scheduled_on FROM billing.subscription WHERE id = :s AND scheduled_price_id IS NOT NULL', ['s' => self::SUB]));
        $page = $this->client->request('GET', '/espace-pro/abonnement');
        self::assertStringContainsString('Changement programmé', $page->filter('.tm-psub__current')->text());
        self::assertStringContainsString('Formule Fictive (mensuel)', $page->filter('.tm-psub__current')->text());
    }

    public function testCancelThenResume(): void
    {
        $this->subscribe(self::BASIC);
        $form = $this->client->request('GET', '/espace-pro/abonnement/resilier')->selectButton('Confirmer la résiliation')->form();
        $form['motif'] = 'price';
        $this->client->submit($form);
        self::assertSame(['subscription_cancel_at_end'], $this->calls());
        self::assertSame('{"reason": "price"}', $this->connection->fetchOne("SELECT context::text FROM admin.audit_log WHERE action = 'billing.cancellation_requested'"));

        $this->connection->executeStatement('UPDATE billing.subscription SET cancel_at_period_end = true WHERE id = :s', ['s' => self::SUB]); // webhook
        $page = $this->client->request('GET', '/espace-pro/abonnement');
        $this->client->submit($page->selectButton('Annuler la résiliation')->form());
        self::assertSame(['subscription_cancel_at_end', 'subscription_resume'], $this->calls());
    }

    public function testChangesAreRefusedWhilePaymentIsLate(): void
    {
        $this->subscribe(self::BASIC, 'past_due');
        $this->client->request('GET', '/espace-pro/abonnement/changer?forfait='.self::UPPER.'&periode=mois');
        self::assertResponseRedirects('/espace-pro/abonnement');
        $page = $this->client->followRedirect();
        self::assertStringContainsString('Réglez d’abord votre facture', (string) $this->client->getResponse()->getContent());
        self::assertCount(0, $page->filter('.tm-psub__manage'));
        self::assertSame([], $this->calls());
    }
}
