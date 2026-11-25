<?php

declare(strict_types=1);

namespace App\Tests\Functional\Quote;

use App\ArtisanProfile\Application\Agenda\SaveWeeklyRules;
use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Billing\Application\Credit\CreditLedger;
use App\Identity\Application\Command\RegisterUser;
use App\Quote\Application\Appointment\AppointmentDecision;
use App\Quote\Application\Client\AcceptQuote;
use App\Quote\Application\Editor\SaveQuoteDraft;
use App\Quote\Application\Sending\SendQuote;
use App\QuoteRequest\Application\Dispatch\DispatchQuoteRequest;
use App\QuoteRequest\Application\Submission\SubmitQuoteRequest;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * T-703c — PR06 « Mes rendez-vous » (artisan) : devis acceptés à planifier, proposition d'un créneau, confirmation par le
 * client, tableau (client, téléphone, statut), agenda du jour, annulation motivée, « Marquer réalisé ».
 */
final class ProAppointmentsPageTest extends WebTestCase
{
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000fc901';
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000ac901';
    private const string REQUEST = '0190f5e4-0000-7000-8000-0000000fc902';
    private const string QUOTE = '0190f5e4-0000-7000-8000-0000000fc903';
    private const string A3 = '0190f5e4-0000-7000-8000-0000000fc9a3';

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
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.pr06@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Fictive'));
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
        $ledger->grantFree('0190f5e4-0000-7000-8000-0000000fc905', $this->artisan, self::DOSSIER_USER, 3, null, new \DateTimeImmutable());
        $this->dossierDispatch(new SendQuote(self::DOSSIER_USER, self::REQUEST));
        $this->dossierDispatch(new AcceptQuote(self::CLIENT, self::QUOTE));
        $this->dossierDispatch(new SaveWeeklyRules(self::DOSSIER_USER, array_map(static fn (int $d): array => ['weekday' => $d, 'start' => '08:00', 'end' => '12:00'], range(1, 7))));
        $this->connection->executeStatement('UPDATE artisan.agenda_settings SET closed_on_holidays = false WHERE artisan_id = :a', ['a' => $this->artisan]);
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

    public function testScheduleConfirmCancelAndComplete(): void
    {
        $page = $this->client->request('GET', '/espace-pro/rendez-vous');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Camille Fictive', $page->filter('#a-planifier')->text());
        self::assertSame('1', $page->filter('.tm-prdv__kpis .is-red strong')->text());

        $this->client->click($page->filter('#a-planifier')->selectLink('Proposer un créneau')->link());
        $book = $this->client->getCrawler();
        $slot = (string) $book->filter('.tm-rdv-book__slot input')->first()->attr('value');
        $this->client->submit($book->filter('form.tm-rdv-book__form')->form(), ['creneau' => $slot]);
        self::assertResponseRedirects('/espace-pro/rendez-vous', 303);
        $page = $this->client->followRedirect();
        self::assertStringContainsString('Proposé au client', $page->filter('.tm-prdv__table')->text());
        self::assertStringContainsString('Tous vos devis acceptés ont un rendez-vous', $page->filter('#a-planifier')->text());

        $id = DbalValue::string($this->connection->fetchOne('SELECT id FROM quote.appointment'));
        $this->dossierDispatch(new AppointmentDecision(self::CLIENT, $id, AppointmentDecision::CONFIRM));
        $page = $this->client->request('GET', '/espace-pro/rendez-vous');
        self::assertStringContainsString('Confirmé', $page->filter('.tm-prdv__table')->text());
        self::assertStringContainsString('Camille Fictive', $page->filter('.tm-prdv__table')->text());

        $this->client->submit($page->selectButton('Annuler le rendez-vous')->form(), ['motif' => 'Intempéries (fictif)']);
        $page = $this->client->followRedirect();
        self::assertStringContainsString('Annulés (1)', $page->filter('.tm-agenda__tabs')->text());
        self::assertStringContainsString('Camille Fictive', $page->filter('#a-planifier')->text(), 'de nouveau à planifier');

        $this->connection->executeStatement("INSERT INTO quote.appointment (id, quote_id, starts_at, ends_at, status, proposed_by, confirmed_at, created_at) VALUES (:id, :q, now() - interval '3 hours', now() - interval '2 hours', 'confirmed', 'artisan', now() - interval '2 days', now() - interval '3 days')", ['id' => self::A3, 'q' => self::QUOTE]);
        $past = $this->client->request('GET', '/espace-pro/rendez-vous?onglet=passes');
        $this->client->submit($past->selectButton('Marquer réalisé')->form());
        self::assertSame('completed', $this->connection->fetchOne('SELECT status FROM quote.appointment WHERE id = :id', ['id' => self::A3]));
    }
}
