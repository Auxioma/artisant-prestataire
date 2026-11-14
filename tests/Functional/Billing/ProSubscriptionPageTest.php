<?php

declare(strict_types=1);

namespace App\Tests\Functional\Billing;

use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Billing\Application\Credit\CreditLedger;
use App\Billing\Application\Invoice\InvoiceArchive;
use App\Billing\Application\Plan\PlanAction;
use App\Billing\Application\Plan\SavePlan;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** PR11 (Figma 167:1401) : offre de lancement, forfait en vigueur signalé dans la grille, historique des crédits. */
final class ProSubscriptionPageTest extends WebTestCase
{
    use ArtisanDossier;

    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000aa603';
    private const string PLAN = '0190f5e4-0000-7000-8000-0000000b6603';

    private KernelBrowser $client;
    private Connection $connection;
    private string $artisan;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->connection = $connection;
        $this->prepareReferentials();
        $this->artisan = $this->submittedDossier(200);
        $this->uploadPiece('kbis');
        $this->uploadPiece('insurance_rc');
        $this->dossierDispatch(new ApproveArtisan($this->artisan, self::ADMIN));
        $connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id = :a", ['a' => self::DOSSIER_USER]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $ledger = self::getContainer()->get(CreditLedger::class);
        \assert($ledger instanceof CreditLedger);
        $ledger->grantFree('0190f5e4-0000-7000-8000-0000000fa603', $this->artisan, self::DOSSIER_USER, 5, null, new \DateTimeImmutable());
        $this->dossierDispatch(new SavePlan(self::ADMIN, null, self::PLAN, ['name' => 'Formule Fictive', 'quotes_per_period' => '20', 'monthly_price' => '29,00', 'features' => "Agenda fictif\nStatistiques fictives", 'featured' => '1']));
        $this->dossierDispatch(new PlanAction(self::ADMIN, self::PLAN, PlanAction::PUBLISH));

        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->request('POST', '/connexion', ['_username' => 'dominique.verif@exemple.test', '_password' => 'cheval agrafe batterie', '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value')]);
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    public function testLaunchOfferAndPlans(): void
    {
        $crawler = $this->client->request('GET', '/espace-pro/abonnement');
        self::assertResponseIsSuccessful();
        self::assertSame('Mes abonnements', $crawler->filter('h1')->text());
        self::assertStringContainsString('Mes abonnements', $crawler->filter('.tm-space__menu [aria-current="page"]')->text());
        self::assertSame('Offre de lancement', $crawler->filter('#actuel-titre')->text());
        self::assertStringContainsString('5 devis gratuits restants', $crawler->filter('.tm-psub__current')->text());
        self::assertSame(['Formule Fictive'], $crawler->filter('.tm-psub__offer-name')->each(static fn ($n): string => $n->text()));
        self::assertMatchesRegularExpression('/29,00\s€ HT \/ mois/u', $crawler->filter('.tm-psub__offer')->text());
        self::assertCount(0, $crawler->filter('.tm-psub__offer.is-current'));
    }

    public function testLiveSubscriptionIsHighlighted(): void
    {
        $price = $this->connection->fetchOne("SELECT id FROM billing.plan_price WHERE plan_id = :p AND active AND interval = 'month'", ['p' => self::PLAN]);
        $this->connection->executeStatement(
            "INSERT INTO billing.subscription (id, artisan_id, plan_id, plan_price_id, interval, status, stripe_subscription_id, current_period_start, current_period_end, created_at, updated_at) VALUES ('0190f5e4-0000-7000-8000-0000000e6603', :a, :p, :price, 'month', 'active', 'sub_FICTIF', now() - interval '10 days', now() + interval '20 days', now(), now())",
            ['a' => $this->artisan, 'p' => self::PLAN, 'price' => $price],
        );

        $crawler = $this->client->request('GET', '/espace-pro/abonnement');
        self::assertSame('Formule Fictive', $crawler->filter('#actuel-titre')->text());
        $current = $crawler->filter('.tm-psub__current')->text();
        self::assertMatchesRegularExpression('/29,00\s€ HT \/ mois/u', $current);
        self::assertStringContainsString('Actif', $current);
        self::assertStringContainsString('Prochain prélèvement', $current);
        self::assertStringContainsString('10 / 30 jours', $current);
        self::assertStringContainsString('Formule actuelle', $crawler->filter('.tm-psub__offer.is-current')->text());
        self::assertStringContainsString('Agenda fictif', $crawler->filter('.tm-psub__included')->text());
        self::assertSame(['Incluse', 'Non incluse', 'Non incluse'], $crawler->filter('.tm-psub__toggle')->each(static fn ($n): string => (string) $n->attr('aria-label')));
        self::assertSame(1, DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM billing.subscription WHERE plan_id = :p', ['p' => self::PLAN])));
    }

    public function testSuspendedSubscriptionIsFlagged(): void
    {
        $price = $this->connection->fetchOne("SELECT id FROM billing.plan_price WHERE plan_id = :p AND active AND interval = 'month'", ['p' => self::PLAN]);
        $this->connection->executeStatement(
            "INSERT INTO billing.subscription (id, artisan_id, plan_id, plan_price_id, interval, status, stripe_subscription_id, current_period_start, current_period_end, past_due_since, created_at, updated_at) VALUES ('0190f5e4-0000-7000-8000-0000000e6604', :a, :p, :price, 'month', 'suspended', 'sub_FICTIF_SUSP', now() - interval '20 days', now() + interval '10 days', now() - interval '9 days', now(), now())",
            ['a' => $this->artisan, 'p' => self::PLAN, 'price' => $price],
        );
        $crawler = $this->client->request('GET', '/espace-pro/abonnement');
        self::assertStringContainsString('Abonnement suspendu pour impayé', $crawler->filter('[role="alert"]')->text());
        self::assertStringContainsString('Suspendu', $crawler->filter('.tm-psub__current .tm-pro-state')->text());
    }

    public function testInvoicesPaymentMethodAndPortal(): void
    {
        $this->connection->executeStatement("UPDATE billing.credit_account SET stripe_customer_id = 'cus_FICTIF_PR13' WHERE artisan_id = :a", ['a' => $this->artisan]);
        $archive = self::getContainer()->get(InvoiceArchive::class);
        \assert($archive instanceof InvoiceArchive);
        $archive->put('0190f5e4-0000-7000-8000-00000001a603', '%PDF-1.4 facture fictive PR13');
        $this->connection->executeStatement(
            "INSERT INTO billing.invoice (id, artisan_id, stripe_invoice_id, number, status, currency, amount_ht_cents, vat_cents, amount_ttc_cents, amount_paid_cents, period_start, period_end, issued_at, paid_at, pdf_key, pdf_sha256, archived_at, updated_at) VALUES ('0190f5e4-0000-7000-8000-00000001a603', :a, 'in_FICTIF_PR13', 'TM-FICTIF-0042', 'paid', 'EUR', 2900, 580, 3480, 3480, '2026-10-14', '2026-11-14', '2026-10-14', '2026-10-14', '0190f5e4-0000-7000-8000-00000001a603', :h, now(), now())",
            ['a' => $this->artisan, 'h' => hash('sha256', '%PDF-1.4 facture fictive PR13')],
        );
        $this->connection->executeStatement("INSERT INTO billing.payment_method (artisan_id, type, brand, last4, exp_month, exp_year, updated_at) VALUES (:a, 'card', 'visa', '4587', 12, 2028, now())", ['a' => $this->artisan]);

        $page = $this->client->request('GET', '/espace-pro/abonnement');
        self::assertStringContainsString('Octobre 2026', $page->filter('.tm-psub__invoices')->text());
        $card = $page->filter('.tm-psub__card')->text();
        self::assertStringContainsString('•••• 4587', $card);
        self::assertStringContainsString('Expire le 12/2028', $card);

        $tab = $this->client->request('GET', '/espace-pro/abonnement?onglet=factures');
        self::assertStringContainsString('TM-FICTIF-0042', $tab->filter('.tm-psub__table')->text());
        self::assertStringContainsString('Payée', $tab->filter('.tm-psub__table')->text());

        $this->client->request('GET', '/espace-pro/abonnement/factures/0190f5e4-0000-7000-8000-00000001a603/pdf');
        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('facture-TM-FICTIF-0042.pdf', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $this->client->request('GET', '/espace-pro/abonnement/factures/0190f5e4-0000-7000-8000-00000001a699/pdf');
        self::assertResponseStatusCodeSame(404);

        $this->client->submit($page->selectButton('Modifier')->form());
        self::assertResponseStatusCodeSame(303);
        self::assertStringStartsWith('https://billing.stripe.test/session/', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testHistoryTabListsLedger(): void
    {
        $crawler = $this->client->request('GET', '/espace-pro/abonnement?onglet=historique');
        self::assertResponseIsSuccessful();
        self::assertSame(['Attribution gratuite', '+5', '5'], [
            $crawler->filter('.tm-psub__table tbody td')->eq(1)->text(),
            $crawler->filter('.tm-psub__table tbody td')->eq(2)->text(),
            $crawler->filter('.tm-psub__table tbody td')->eq(3)->text(),
        ]);
    }
}
