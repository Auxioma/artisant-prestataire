<?php

declare(strict_types=1);

namespace App\Tests\Functional\Quote;

use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Billing\Application\Credit\CreditLedger;
use App\Identity\Application\Command\RegisterUser;
use App\Quote\Application\Client\AcceptQuote;
use App\Quote\Application\Editor\SaveQuoteDraft;
use App\Quote\Application\Sending\SendQuote;
use App\QuoteRequest\Application\Dispatch\DispatchQuoteRequest;
use App\QuoteRequest\Application\Submission\SubmitQuoteRequest;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** PR01 (Figma 22:150) : indicateurs, dernières demandes, crédit de devis (RG-106), activité, visibilité, page d’arrivée. */
final class ProDashboardPageTest extends WebTestCase
{
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000fa001';
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000aa001';
    private const string REQUEST = '0190f5e4-0000-7000-8000-0000000fa101';
    private const string QUOTE = '0190f5e4-0000-7000-8000-0000000fa201';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->prepareReferentials();
        $artisan = $this->submittedDossier(200);
        $this->uploadPiece('kbis');
        $this->uploadPiece('insurance_rc');
        $this->dossierDispatch(new ApproveArtisan($artisan, self::ADMIN));
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.pr01@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Liste'));
        $connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id IN (:a, :b)", ['a' => self::DOSSIER_USER, 'b' => self::CLIENT]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $job = $this->dossierValue("SELECT id FROM catalog.job WHERE slug = 'plombier'");
        $this->dossierDispatch(new SubmitQuoteRequest(self::REQUEST, self::CLIENT, $job, '76351', '76600', '1 rue Fictive', 'repair', 'Fuite sous l’évier de la cuisine depuis ce matin (fictif).', [], '500_1000', 'week', null, title: 'Fuite sous l’évier'));
        $this->dossierDispatch(new DispatchQuoteRequest(self::REQUEST));
        $this->dossierDispatch(new SaveQuoteDraft(self::DOSSIER_USER, self::REQUEST, self::QUOTE, ['subject' => 'Réparation de la fuite', 'lines' => [
            ['type' => 'labour', 'label' => 'Recherche de fuite', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '90', 'vat' => '1000'],
            ['type' => 'travel', 'label' => 'Déplacement', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '0', 'vat' => '2000'],
        ]]));
        $ledger = self::getContainer()->get(CreditLedger::class);
        \assert($ledger instanceof CreditLedger);
        $ledger->grantFree('0190f5e4-0000-7000-8000-0000000fa301', $artisan, self::DOSSIER_USER, 1, null, new \DateTimeImmutable());
        $this->dossierDispatch(new SendQuote(self::DOSSIER_USER, self::REQUEST));
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->request('POST', '/connexion', ['_username' => 'dominique.verif@exemple.test', '_password' => 'cheval agrafe batterie', '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value')]);
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    public function testDashboardSummarisesActivity(): void
    {
        $crawler = $this->client->request('GET', '/espace-pro');
        self::assertResponseIsSuccessful();
        self::assertSame('Tableau de bord', $crawler->filter('.tm-space__banner-title')->text());
        self::assertStringContainsString('Tableau de bord', $crawler->filter('.tm-space__menu [aria-current="page"]')->text());
        self::assertSame(['0', '1', '0', '0'], $crawler->filter('.tm-pdash__kpis strong')->each(static fn ($n): string => $n->text()), 'nouvelles demandes, devis en cours, acceptés, clients');
        self::assertSame('Fuite sous l’évier', $crawler->filter('.tm-pdash__what a')->text());
        self::assertStringContainsString('Envoyé', $crawler->filter('.tm-pdash__state')->text());
        self::assertSame('exhausted', str_replace('tm-credit-gauge tm-credit-gauge--', '', (string) $crawler->filter('.tm-credit-gauge')->attr('class')), 'RG-106 : crédit épuisé');
        self::assertStringContainsString('Crédit épuisé', $crawler->filter('.tm-credit-gauge')->text());
        self::assertSame(['1', '1', '0', '0 €'], $crawler->filter('.tm-pdash__activity strong')->each(static fn ($n): string => $n->text()), 'reçues, envoyés, acceptés, CA');
        self::assertStringContainsString('Profil vérifié', $crawler->filter('.tm-pdash__visibility')->text());

        $this->dossierDispatch(new AcceptQuote(self::CLIENT, self::QUOTE));
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $crawler = $this->client->request('GET', '/espace-pro?periode=365');
        self::assertSame(['0', '0', '1', '1'], $crawler->filter('.tm-pdash__kpis strong')->each(static fn ($n): string => $n->text()));
        self::assertSame('99 €', $crawler->filter('.tm-pdash__activity strong')->last()->text());
    }
}
