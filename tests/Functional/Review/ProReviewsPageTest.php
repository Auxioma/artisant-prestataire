<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Billing\Application\Credit\CreditLedger;
use App\Identity\Application\Command\RegisterUser;
use App\Quote\Application\Client\AcceptQuote;
use App\Quote\Application\Editor\SaveQuoteDraft;
use App\Quote\Application\Sending\SendQuote;
use App\QuoteRequest\Application\Dispatch\DispatchQuoteRequest;
use App\QuoteRequest\Application\Submission\SubmitQuoteRequest;
use App\Review\Application\Review\SubmitReview;
use App\Shared\Application\Moderation\ModerateReview;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * T-704c — PR09 « Avis reçus » : solliciter (relance unique), synthèse (note, répartition, sous-notes), réponse
 * publique modérée, signalement (l'avis reste en ligne).
 */
final class ProReviewsPageTest extends WebTestCase
{
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000f9501';
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a9501';
    private const string REQUEST = '0190f5e4-0000-7000-8000-0000000f9502';
    private const string QUOTE = '0190f5e4-0000-7000-8000-0000000f9503';
    private const string A3 = '0190f5e4-0000-7000-8000-0000000f95a3';
    private const string R1 = '0190f5e4-0000-7000-8000-0000000f95b1';

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
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.pr09@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Fictive'));
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
        $ledger->grantFree('0190f5e4-0000-7000-8000-0000000f9505', $this->artisan, self::DOSSIER_USER, 3, null, new \DateTimeImmutable());
        $this->dossierDispatch(new SendQuote(self::DOSSIER_USER, self::REQUEST));
        $this->dossierDispatch(new AcceptQuote(self::CLIENT, self::QUOTE));
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

    public function testSolicitSummaryAnswerAndReport(): void
    {
        $this->connection->executeStatement("INSERT INTO quote.appointment (id, quote_id, starts_at, ends_at, status, proposed_by, confirmed_at, completed_at, created_at) VALUES (:id, :q, now() - interval '2 days', now() - interval '2 days' + interval '1 hour', 'completed', 'client', now() - interval '4 days', now() - interval '1 day', now() - interval '5 days')", ['id' => self::A3, 'q' => self::QUOTE]);
        $page = $this->client->request('GET', '/espace-pro/avis');
        self::assertResponseIsSuccessful();
        self::assertSame('0 avis certifié', $page->filter('.tm-pr header p')->text());
        self::assertStringContainsString('Camille F.', $page->filter('.tm-pr__solicit')->text());
        $this->client->submit($page->filter('.tm-pr__solicit')->selectButton('Relancer le client (1 fois)')->form());
        $page = $this->client->followRedirect();
        self::assertStringContainsString('Client relancé', $page->filter('.tm-pr__solicit')->text());
        self::assertSame(1, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM notification.delivery WHERE code = 'E-16' AND channel = 'email'")));

        $this->dossierDispatch(new SubmitReview(self::CLIENT, self::R1, self::REQUEST, 4, ['quality' => 5, 'punctuality' => 3], 'Bien (fictif)', 'Intervention rapide, travail soigné et chantier laissé propre. Je recommande (fictif).', null, true));
        $this->dossierDispatch(new ModerateReview(self::ADMIN, self::R1, 'publish'));
        $page = $this->client->request('GET', '/espace-pro/avis');
        self::assertSame('4,0', $page->filter('.tm-pr__score strong')->text());
        self::assertStringContainsString('Qualité5,0', $page->filter('.tm-pr__criteria')->text());
        self::assertStringContainsString('Camille F.', $page->filter('.tm-pr__review')->text());
        self::assertStringContainsString('Aucune prestation terminée sans avis', $page->filter('.tm-pr__solicit')->text());

        $this->client->submit($page->filter('.tm-pr__answer')->form(), ['reponse' => 'Merci Camille, au plaisir (texte fictif).']);
        $page = $this->client->followRedirect();
        self::assertSame('pending', $this->connection->fetchOne('SELECT reply_status FROM review.review'));
        self::assertStringContainsString('en modération', $page->filter('.tm-pr__answer')->text());

        $this->client->submit($page->filter('.tm-pr__report form')->form(), ['motif' => 'Contestation (fictif)']);
        $page = $this->client->followRedirect();
        self::assertStringContainsString('Signalé · en examen', $page->filter('.tm-pr__review')->text());
        self::assertSame('published', $this->connection->fetchOne('SELECT status FROM review.review'));
    }
}
