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

/** PR05 (Figma 167:1749) : liste, onglets Devis / Chantiers, recherche, filtre de statut, avancement, export CSV. */
final class ProQuotesPageTest extends WebTestCase
{
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000f9001';
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a9001';
    private const string REQUEST = '0190f5e4-0000-7000-8000-0000000f9101';
    private const string QUOTE = '0190f5e4-0000-7000-8000-0000000f9201';

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
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.pr05@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Liste'));
        $connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id IN (:a, :b)", ['a' => self::DOSSIER_USER, 'b' => self::CLIENT]);
        // Défense en profondeur : la validation refuse ce prénom, mais l'export CSV doit neutraliser toute formule.
        $connection->executeStatement("UPDATE identity.user_account SET first_name = '=Camille' WHERE id = :id", ['id' => self::CLIENT]);
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
        $ledger->grantFree('0190f5e4-0000-7000-8000-0000000f9301', $artisan, self::DOSSIER_USER, 1, null, new \DateTimeImmutable());
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

    public function testListFiltersAndExport(): void
    {
        $crawler = $this->client->request('GET', '/espace-pro/devis');
        self::assertResponseIsSuccessful();
        self::assertSame('Mes devis & chantiers', $crawler->filter('h1')->text());
        self::assertSame('page', $crawler->filter('.tm-space__menu [aria-current="page"]')->attr('aria-current'));
        self::assertStringContainsString('Mes devis & chantiers', $crawler->filter('.tm-space__menu [aria-current="page"]')->text());
        self::assertSame(['Devis (1)', 'Chantiers (0)'], $crawler->filter('.tm-pro-tabs a')->each(static fn ($a): string => $a->text()));
        $card = $crawler->filter('.tm-pro-quote');
        self::assertCount(1, $card);
        self::assertSame('Fuite sous l’évier', $card->filter('h2')->text());
        self::assertStringContainsString("99,00\u{a0}€ TTC", $card->text());
        self::assertSame('Envoyé', trim($card->filter('.tm-pro-state')->text()));
        self::assertSame('Devis envoyé', $card->filter('.tm-pro-steps [aria-current="step"]')->text());
        self::assertSame(['1', '0', '0', '0'], $crawler->filter('.tm-pro-figure')->each(static fn ($n): string => $n->text()));

        self::assertCount(0, $this->client->request('GET', '/espace-pro/devis?q=chauffe')->filter('.tm-pro-quote'));
        self::assertCount(1, $this->client->request('GET', '/espace-pro/devis?q=fuite')->filter('.tm-pro-quote'));
        self::assertCount(0, $this->client->request('GET', '/espace-pro/devis?statut=accepted')->filter('.tm-pro-quote'));

        $this->client->request('GET', '/espace-pro/devis/export.csv');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $this->client->getInternalResponse()->getContent();
        self::assertStringStartsWith("\u{FEFF}Numéro;Demande;Client;Commune;Métier;\"Montant TTC\"", $csv);
        self::assertStringContainsString(";'=Camille;", $csv, 'formule neutralisée');
        self::assertStringContainsString(';Envoyé;v1', $csv);
    }

    public function testAcceptedQuoteIsAWork(): void
    {
        $this->dossierDispatch(new AcceptQuote(self::CLIENT, self::QUOTE));
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $crawler = $this->client->request('GET', '/espace-pro/devis?onglet=chantiers');
        self::assertSame('Chantiers (1)', $crawler->filter('.tm-pro-tabs [aria-current="page"]')->text());
        $card = $crawler->filter('.tm-pro-quote');
        self::assertSame('Devis accepté', $card->filter('.tm-pro-steps [aria-current="step"]')->text());
        self::assertCount(1, $card->filter('a[href$="#contact-title"]'), '« Contacter le client »');
        self::assertCount(0, $card->filter('a[href*="reviser=1"]'), 'plus de révision');
        self::assertSame(['0', '1', '1', '0'], $crawler->filter('.tm-pro-figure')->each(static fn ($n): string => $n->text()));
    }
}
