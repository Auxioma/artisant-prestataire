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
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\AdminSession;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * T-704d — BO05.1 « Avis » (preuve de certification, score, décisions, motif obligatoire) et P10.9 sur la fiche publique
 * (note, répartition, sous-notes, filtres, mention de contrôle, AggregateRating).
 */
final class ReviewModerationAndProfileTest extends WebTestCase
{
    use AdminSession;
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000f8501';
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a8501';
    private const string REQUEST = '0190f5e4-0000-7000-8000-0000000f8502';
    private const string QUOTE = '0190f5e4-0000-7000-8000-0000000f8503';
    private const string A3 = '0190f5e4-0000-7000-8000-0000000f85a3';
    private const string R1 = '0190f5e4-0000-7000-8000-0000000f85b1';

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
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.bo051@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Fictive'));
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
        $ledger->grantFree('0190f5e4-0000-7000-8000-0000000f8505', $this->artisan, self::DOSSIER_USER, 3, null, new \DateTimeImmutable());
        $this->dossierDispatch(new SendQuote(self::DOSSIER_USER, self::REQUEST));
        $this->dossierDispatch(new AcceptQuote(self::CLIENT, self::QUOTE));
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->connection->executeStatement("INSERT INTO quote.appointment (id, quote_id, starts_at, ends_at, status, proposed_by, confirmed_at, completed_at, created_at) VALUES (:id, :q, now() - interval '2 days', now() - interval '2 days' + interval '1 hour', 'completed', 'client', now() - interval '4 days', now() - interval '1 day', now() - interval '5 days')", ['id' => self::A3, 'q' => self::QUOTE]);
        $this->dossierDispatch(new SubmitReview(self::CLIENT, self::R1, self::REQUEST, 4, ['quality' => 5], 'Très bien (fictif)', 'Intervention rapide, travail soigné et chantier laissé propre. Je recommande (fictif).', null, true));
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    public function testModeratorPublishesThenProfileShowsTheReview(): void
    {
        $this->client->request('GET', '/pro/'.$this->artisan);
        $profile = $this->client->followRedirect();
        self::assertStringContainsString('pas encore reçu d’avis', $profile->filter('#avis')->text(), 'en modération : rien de public');

        $this->adminSession($this->client, self::ADMIN, 'moderator');
        $page = $this->client->request('GET', '/admin/moderation/avis');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Avis (1)', $page->filter('.tm-admin-tabs')->text());
        self::assertStringContainsString('Avis certifié : devis', $page->filter('.tm-moderation__proof')->text());
        $this->client->submit($page->selectButton('Refuser')->form());
        self::assertStringContainsString('motif', (string) $this->client->followRedirect()->html());
        self::assertSame('pending', $this->connection->fetchOne('SELECT status FROM review.review'));
        $page = $this->client->request('GET', '/admin/moderation/avis');
        $this->client->submit($page->selectButton('Publier')->form());
        self::assertSame('published', $this->connection->fetchOne('SELECT status FROM review.review'));
        self::assertSame(1, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM admin.audit_log WHERE action = 'review.moderated'")));

        $this->client->request('GET', '/pro/'.$this->artisan);
        $profile = $this->client->followRedirect();
        self::assertStringContainsString('4,0', $profile->filter('.tm-profile__rating')->text());
        self::assertStringContainsString('Avis certifié — prestation du', $profile->filter('.tm-profile__reviews')->text());
        self::assertStringContainsString('Camille F.', $profile->filter('.tm-profile__reviews')->text());
        self::assertStringContainsString('"aggregateRating"', (string) $this->client->getResponse()->getContent());
        $filtered = $this->client->request('GET', $this->client->getRequest()->getPathInfo().'?note=5');
        self::assertStringContainsString('Aucun avis avec cette note', $filtered->filter('.tm-profile__reviews')->text());
    }
}
