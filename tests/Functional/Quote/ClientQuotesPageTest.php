<?php

declare(strict_types=1);

namespace App\Tests\Functional\Quote;

use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Billing\Application\Credit\CreditLedger;
use App\Identity\Application\Command\RegisterUser;
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

/** C03 (Figma 22:80) : comparateur, PDF (devis consulté), acceptation (195:9075), refus motivé, cloisonnement. */
final class ClientQuotesPageTest extends WebTestCase
{
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000f8001';
    private const string OTHER = '0190f5e4-0000-7000-8000-0000000f8002';
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a8001';
    private const string REQUEST = '0190f5e4-0000-7000-8000-0000000f8101';
    private const string QUOTE = '0190f5e4-0000-7000-8000-0000000f8201';

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
        foreach ([[self::CLIENT, 'camille.compare@exemple.test'], [self::OTHER, 'autre.compare@exemple.test']] as [$id, $email]) {
            $this->dossierDispatch(new RegisterUser($id, $email, 'cheval agrafe batterie', 'client', 'Camille', 'Compare'));
        }
        $connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id IN (:a, :b, :c)", ['a' => self::DOSSIER_USER, 'b' => self::CLIENT, 'c' => self::OTHER]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $job = $this->dossierValue("SELECT id FROM catalog.job WHERE slug = 'plombier'");
        $this->dossierDispatch(new SubmitQuoteRequest(self::REQUEST, self::CLIENT, $job, '76351', '76600', '1 rue Fictive', 'repair', 'Fuite sous l’évier de la cuisine depuis ce matin (fictif).', [], null, 'week', null, title: 'Fuite sous l’évier'));
        $this->dossierDispatch(new DispatchQuoteRequest(self::REQUEST));
        $this->dossierDispatch(new SaveQuoteDraft(self::DOSSIER_USER, self::REQUEST, self::QUOTE, ['subject' => 'Réparation de la fuite', 'execution_delay' => '2 semaines', 'deposit_type' => 'percent', 'deposit_value' => '30', 'lines' => [
            ['type' => 'labour', 'label' => 'Recherche de fuite', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '90', 'vat' => '1000'],
            ['type' => 'travel', 'label' => 'Déplacement', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '0', 'vat' => '2000'],
        ]]));
        $ledger = self::getContainer()->get(CreditLedger::class);
        \assert($ledger instanceof CreditLedger);
        $ledger->grantFree('0190f5e4-0000-7000-8000-0000000f8301', $artisan, self::DOSSIER_USER, 1, null, new \DateTimeImmutable());
        $this->dossierDispatch(new SendQuote(self::DOSSIER_USER, self::REQUEST));
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->login('camille.compare@exemple.test');
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    public function testCompareViewAndAccept(): void
    {
        $crawler = $this->client->request('GET', '/espace-client/demandes');
        self::assertSame('/espace-client/demandes/'.self::REQUEST, $crawler->filter('.tm-crequest a.tm-button')->reduce(static fn ($a): bool => str_contains($a->text(), 'Voir'))->attr('href'), 'C02 : « Voir » mène à C03');

        $crawler = $this->client->request('GET', '/espace-client/demandes/'.self::REQUEST);
        self::assertResponseIsSuccessful();
        self::assertSame('Comparateur de devis', $crawler->filter('.tm-cspace__banner-title')->text());
        self::assertStringContainsString('1 devis disponible sur 3', $crawler->filter('.tm-compare__count')->text());
        $card = $crawler->filter('.tm-qcard');
        self::assertSame('Atelier Fictif', $card->filter('h2')->text());
        self::assertSame("99,00\u{a0}€", $card->filter('.tm-qcard__price')->text());
        self::assertStringContainsString('TVA 10 %', $card->filter('.tm-qcard__amounts')->text());
        self::assertSame(['30 %'], $crawler->filter('.tm-compare__table tbody tr')->eq(3)->filter('td')->each(static fn ($td): string => $td->text()), 'acompte');

        $this->client->request('GET', '/espace-client/devis/'.self::QUOTE.'/pdf');
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertSame('viewed', $this->dossierValue("SELECT status FROM quote.quote WHERE id = '".self::QUOTE."'"), 'consulté à l’ouverture');

        self::assertSame('Accepter le devis d’Atelier Fictif ?', $crawler->filter('#accepter-'.self::QUOTE.'_title')->text(), 'élision');
        $this->client->submit($crawler->filter('#accepter-'.self::QUOTE.' button[type="submit"]')->form());
        self::assertResponseRedirects('/espace-client/demandes/'.self::REQUEST);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Devis accepté', $crawler->filter('.tm-toasts')->text());
        self::assertSame('accepted', $this->dossierValue("SELECT status FROM quote.quote WHERE id = '".self::QUOTE."'"));
        self::assertCount(0, $crawler->filter('.tm-qcard__choose'), 'plus de choix possible');
        self::assertSame('Accepté', $crawler->filter('.tm-qcard__badge')->text());
    }

    public function testRefuseWithReason(): void
    {
        $crawler = $this->client->request('GET', '/espace-client/demandes/'.self::REQUEST);
        $form = $crawler->filter('#refuser-'.self::QUOTE.' button[type="submit"]')->form();
        $this->client->submit($form, ['reason' => 'delay', 'comment' => 'Trop tard pour nous (fictif).']);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Devis refusé', $crawler->filter('.tm-toasts')->text());
        self::assertSame('Délai — Trop tard pour nous (fictif).', $this->dossierValue("SELECT decision_reason FROM quote.quote WHERE id = '".self::QUOTE."'"));
        self::assertStringContainsString('Motif : Délai', $crawler->filter('.tm-qcard')->text());
    }

    public function testOtherClientsCannotSeeOrDecide(): void
    {
        $this->client->getCookieJar()->clear();
        $this->login('autre.compare@exemple.test');
        $this->client->request('GET', '/espace-client/demandes/'.self::REQUEST);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/espace-client/devis/'.self::QUOTE.'/pdf');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('sent', $this->dossierValue("SELECT status FROM quote.quote WHERE id = '".self::QUOTE."'"));
    }

    private function login(string $email): void
    {
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->request('POST', '/connexion', ['_username' => $email, '_password' => 'cheval agrafe batterie', '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value')]);
    }
}
