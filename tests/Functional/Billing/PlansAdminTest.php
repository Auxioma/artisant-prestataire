<?php

declare(strict_types=1);

namespace App\Tests\Functional\Billing;

use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\AdminSession;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * BO08 (Figma 186:3467) : forfaits seedés à fixer, création, prix, publication, page Tarifs P13, paramètres de crédits
 * journalisés, droits (lecture seule sans permission d'édition).
 */
final class PlansAdminTest extends WebTestCase
{
    use AdminSession;

    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000ab801';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
    }

    public function testCreatePriceAndPublishThenShowOnPricing(): void
    {
        $this->adminSession($this->client, self::ADMIN, 'finance');
        $crawler = $this->client->request('GET', '/admin/forfaits');
        self::assertResponseIsSuccessful();
        self::assertSame('Forfaits, crédits et codes promo', $crawler->filter('h1')->text());
        self::assertStringContainsString('À fixer', $crawler->filter('tbody tr')->first()->text(), 'Q5 : prix à fixer');
        self::assertStringContainsString('Les forfaits mensuels et annuels seront présentés ici', $this->client->request('GET', '/tarifs-professionnels')->text());

        $crawler = $this->client->request('GET', '/admin/forfaits/nouveau');
        $this->client->submit($crawler->filter('form.tm-plan-form')->form(), ['name' => 'Découverte Fictive', 'quotes_per_period' => '10', 'monthly_price' => '12,50', 'features' => "Avantage fictif A\nAvantage fictif B", 'badge' => 'Le plus choisi']);
        self::assertResponseRedirects('/admin/forfaits');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Forfait créé en brouillon', $crawler->filter('.tm-toasts')->text());
        $row = $crawler->filter('tbody tr')->reduce(static fn ($r): bool => str_contains($r->text(), 'Découverte Fictive'));
        self::assertStringContainsString("12,50\u{a0}€", $row->text());

        $this->client->submit($row->filter('button[value="publish"]')->form());
        self::assertStringContainsString('Forfait publié', $this->client->followRedirect()->filter('.tm-toasts')->text());

        $crawler = $this->client->request('GET', '/tarifs-professionnels');
        self::assertSame('Des forfaits simples pour développer votre activité', $crawler->filter('h1')->text());
        self::assertSame(['Découverte Fictive'], $crawler->filter('.tm-plan h3')->each(static fn ($n): string => $n->text()));
        self::assertStringContainsString('10 devis par mois', $crawler->filter('.tm-plan')->text());
        self::assertCount(1, $crawler->filter('.tm-plan.is-highlighted'));
    }

    public function testCreditSettingsAreSavedAndAudited(): void
    {
        $this->adminSession($this->client, self::ADMIN, 'finance');
        $crawler = $this->client->request('GET', '/admin/forfaits');
        $this->client->submit($crawler->filter('#credits form')->form(), ['free' => '50', 'grace' => '10', 'thresholds' => '80, 90, 100', 'plan_alerts' => '80, 100']);
        self::assertResponseRedirects('/admin/forfaits#credits');
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        self::assertSame('50', DbalValue::string($connection->fetchOne("SELECT value FROM admin.setting WHERE key = 'credits.free_on_signup'")));
        self::assertGreaterThan(0, DbalValue::int($connection->fetchOne("SELECT count(*) FROM admin.audit_log WHERE action = 'administration.setting_changed'")));
    }

    public function testSupportCannotCreatePlans(): void
    {
        $this->adminSession($this->client, self::ADMIN, 'moderator');
        $this->client->request('GET', '/admin/forfaits');
        self::assertResponseStatusCodeSame(403);
    }

    public function testReconciliationIssuesAreShownAndResolvable(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $connection->executeStatement("INSERT INTO billing.reconciliation_issue (id, kind, reference, details, detected_at, last_seen_at) VALUES ('0190f5e4-0000-7000-8000-00000001f617', 'invoice_missing', 'in_FICTIF', 'Facture « paid » de 3480 centimes payés.', now(), now())");
        $connection->executeStatement("INSERT INTO billing.reconciliation_run (id, started_at, finished_at, stripe_checked, checked, issues) VALUES ('0190f5e4-0000-7000-8000-00000001f618', now(), now(), true, 42, 1)");

        $this->adminSession($this->client, self::ADMIN, 'finance');
        $page = $this->client->request('GET', '/admin/forfaits');
        $banner = $page->filter('#rapprochement')->text();
        self::assertStringContainsString('42 éléments vérifiés', $banner);
        self::assertStringContainsString('1 écart ouvert', $banner);
        self::assertStringContainsString('Facture Stripe non répliquée', $banner);

        $this->client->submit($page->selectButton('Marquer résolu : Facture Stripe non répliquée in_FICTIF')->form());
        self::assertResponseRedirects('/admin/forfaits#rapprochement');
        self::assertStringContainsString('0 écart ouvert', $this->client->request('GET', '/admin/forfaits')->filter('#rapprochement')->text());
    }
}
