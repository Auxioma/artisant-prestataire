<?php

declare(strict_types=1);

namespace App\Tests\Functional\Quote;

use App\ArtisanProfile\Application\Agenda\SaveWeeklyRules;
use App\ArtisanProfile\Application\Agenda\SlotReservations;
use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Billing\Application\Credit\CreditLedger;
use App\Identity\Application\Command\RegisterUser;
use App\Quote\Application\Appointment\AppointmentDecision;
use App\Quote\Application\Appointment\ProposeAppointment;
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
 * T-703b — écrans client : suivi du rendez-vous sur C03B, prise de rendez-vous C03.6 (créneaux de l'agenda), C10
 * « Mes rendez-vous » (onglets, annulation motivée, « La prestation a-t-elle eu lieu ? »).
 */
final class ClientAppointmentsPageTest extends WebTestCase
{
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000fb801';
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000ab801';
    private const string REQUEST = '0190f5e4-0000-7000-8000-0000000fb802';
    private const string QUOTE = '0190f5e4-0000-7000-8000-0000000fb803';
    private const string A3 = '0190f5e4-0000-7000-8000-0000000fb8a3';

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
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.c10@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Fictive'));
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
        $ledger->grantFree('0190f5e4-0000-7000-8000-0000000fb805', $this->artisan, self::DOSSIER_USER, 3, null, new \DateTimeImmutable());
        $this->dossierDispatch(new SendQuote(self::DOSSIER_USER, self::REQUEST));
        $this->dossierDispatch(new AcceptQuote(self::CLIENT, self::QUOTE));
        $this->dossierDispatch(new SaveWeeklyRules(self::DOSSIER_USER, array_map(static fn (int $d): array => ['weekday' => $d, 'start' => '08:00', 'end' => '12:00'], range(1, 7))));
        $this->connection->executeStatement('UPDATE artisan.agenda_settings SET closed_on_holidays = false WHERE artisan_id = :a', ['a' => $this->artisan]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->request('POST', '/connexion', ['_username' => 'camille.c10@exemple.test', '_password' => 'cheval agrafe batterie', '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value')]);
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    public function testBookTrackCancelAndComplete(): void
    {
        $page = $this->client->request('GET', '/espace-client/demandes/'.self::REQUEST);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Suivi du rendez-vous', $page->filter('.tm-rdv-track')->text());
        $this->client->click($page->filter('.tm-rdv-track')->selectLink('Prendre rendez-vous')->link());
        self::assertResponseIsSuccessful();
        $book = $this->client->getCrawler();
        self::assertGreaterThan(0, $book->filter('.tm-rdv-book__slot')->count());
        $form = $book->filter('form.tm-rdv-book__form')->form();
        $slot = (string) $book->filter('.tm-rdv-book__slot input')->first()->attr('value');
        $this->client->submit($form, ['creneau' => $slot, 'note' => 'Sonner deux fois (fictif)']);
        self::assertResponseRedirects('/espace-client/rendez-vous', 303);
        $list = $this->client->followRedirect();
        self::assertStringContainsString('À venir (1)', $list->filter('.tm-agenda__tabs')->text());
        self::assertStringContainsString('En attente de confirmation', $list->filter('.tm-rdv__card')->text());
        self::assertStringContainsString('Atelier Fictif', $list->filter('.tm-rdv__next')->text());

        $id = DbalValue::string($this->connection->fetchOne('SELECT id FROM quote.appointment'));
        $this->dossierDispatch(new AppointmentDecision(self::DOSSIER_USER, $id, AppointmentDecision::CONFIRM));
        $track = $this->client->request('GET', '/espace-client/demandes/'.self::REQUEST);
        self::assertCount(2, $track->filter('.tm-rdv-track__steps .is-done'));
        self::assertStringContainsString('confirmé', $track->filter('.tm-rdv-track__when')->text());

        $list = $this->client->request('GET', '/espace-client/rendez-vous');
        $this->client->submit($list->selectButton('Annuler le rendez-vous')->form(), ['motif' => 'Empêchement (fictif)']);
        $list = $this->client->followRedirect();
        self::assertStringContainsString('Annulés (1)', $list->filter('.tm-agenda__tabs')->text());
        self::assertStringContainsString('Empêchement (fictif)', $this->client->request('GET', '/espace-client/rendez-vous?onglet=annules')->filter('.tm-rdv__card')->text());
        self::assertSame('open', $this->connection->fetchOne('SELECT status FROM artisan.availability_slot WHERE starts_at = :s', ['s' => $slot]));

        // Rendez-vous passé : « La prestation a-t-elle eu lieu ? ».
        $this->connection->executeStatement("INSERT INTO quote.appointment (id, quote_id, starts_at, ends_at, status, proposed_by, confirmed_at, created_at) VALUES (:id, :q, now() - interval '3 hours', now() - interval '2 hours', 'confirmed', 'client', now() - interval '2 days', now() - interval '3 days')", ['id' => self::A3, 'q' => self::QUOTE]);
        $past = $this->client->request('GET', '/espace-client/rendez-vous?onglet=passes');
        self::assertStringContainsString('La prestation a-t-elle eu lieu ?', $past->filter('.tm-rdv__card')->text());
        $this->client->submit($past->selectButton('Oui, elle a eu lieu')->form());
        self::assertSame('completed', $this->connection->fetchOne('SELECT status FROM request.quote_request WHERE id = :id', ['id' => self::REQUEST]));
    }

    public function testStrangerCannotBook(): void
    {
        $this->client->request('GET', '/espace-client/rendez-vous/nouveau?devis=0190f5e4-0000-7000-8000-0000000fb899');
        self::assertResponseStatusCodeSame(404);
    }

    public function testCalendarFileAndCancelAcceptance(): void
    {
        $slots = self::getContainer()->get(SlotReservations::class);
        \assert($slots instanceof SlotReservations);
        $start = $slots->openSlots($this->artisan, new \DateTimeImmutable('+3 days'), new \DateTimeImmutable('+20 days'))[0][0];
        $this->dossierDispatch(new ProposeAppointment(self::CLIENT, self::A3, self::QUOTE, $start->format(\DATE_ATOM)));
        $this->dossierDispatch(new AppointmentDecision(self::DOSSIER_USER, self::A3, AppointmentDecision::CONFIRM));

        $this->client->request('GET', '/espace-client/rendez-vous/'.self::A3.'.ics');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('SUMMARY:Rendez-vous avec Atelier Fictif (TrouveMoi)', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('DTSTART:'.$start->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'), (string) $this->client->getResponse()->getContent());

        // RG-072 : rendez-vous confirmé dans moins de 48 h → plus d'annulation d'acceptation.
        $this->connection->executeStatement("UPDATE quote.appointment SET starts_at = now() + interval '10 hours', ends_at = now() + interval '11 hours' WHERE id = :id", ['id' => self::A3]);
        self::assertCount(0, $this->client->request('GET', '/espace-client/demandes/'.self::REQUEST)->filter('.tm-accepted__revoke'));
        $this->connection->executeStatement('UPDATE quote.appointment SET starts_at = :s, ends_at = :e WHERE id = :id', ['s' => $start->format(\DATE_ATOM), 'e' => $start->modify('+1 hour')->format(\DATE_ATOM), 'id' => self::A3]);

        $page = $this->client->request('GET', '/espace-client/demandes/'.self::REQUEST);
        $this->client->submit($page->filter('.tm-accepted__revoke form')->form(), ['motif' => 'Changement de projet (fictif)']);
        self::assertSame('refused', $this->connection->fetchOne('SELECT status FROM quote.quote WHERE id = :q', ['q' => self::QUOTE]));
        self::assertContains($this->connection->fetchOne('SELECT status FROM request.quote_request WHERE id = :r', ['r' => self::REQUEST]), ['open', 'full']);
        self::assertSame('cancelled', $this->connection->fetchOne('SELECT status FROM quote.appointment WHERE id = :id', ['id' => self::A3]));
        self::assertSame('open', $this->connection->fetchOne('SELECT status FROM artisan.availability_slot WHERE starts_at = :s', ['s' => $start->format(\DATE_ATOM)]));
        self::assertStringContainsString('Acceptation annulée — Changement de projet (fictif)', DbalValue::string($this->connection->fetchOne('SELECT decision_reason FROM quote.quote WHERE id = :q', ['q' => self::QUOTE])));
    }
}
