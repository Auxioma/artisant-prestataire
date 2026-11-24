<?php

declare(strict_types=1);

namespace App\Tests\Functional\ArtisanProfile;

use App\ArtisanProfile\Application\PublicProfile\PublicArtisanProfile;
use App\ArtisanProfile\Application\PublicProfile\PublicProfileView;
use App\ArtisanProfile\Application\Search\SearchableArtisan;
use App\ArtisanProfile\Application\Search\SearchableArtisanOf;
use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * PR16 « Mes disponibilités » (Figma 383:17652, agenda PR06) : horaires récurrents et réglages, planning semaine / mois
 * / liste, copie lundi → vendredi, indisponibilités, jours fériés, mode pause.
 */
final class AgendaPageTest extends WebTestCase
{
    use ArtisanDossier;

    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000ae701';

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
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->request('POST', '/connexion', ['_username' => 'dominique.verif@exemple.test', '_password' => 'cheval agrafe batterie', '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value')]);
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    public function testRecurringHoursFillThePlanning(): void
    {
        $page = $this->client->request('GET', '/espace-pro/agenda');
        self::assertResponseIsSuccessful();
        self::assertSame('Mes disponibilités', $page->filter('h1')->text());
        self::assertStringContainsString('Aucun horaire défini', $page->filter('.tm-agenda__planning')->text());
        self::assertStringContainsString('Mes disponibilités', $page->filter('.tm-space__link.is-active')->first()->text(), 'menu actif');

        $form = $this->client->request('GET', '/espace-pro/agenda?onglet=horaires')->filter('form#horaires')->form();
        $this->client->submit($form, ['plages[1][0][debut]' => '08:00', 'plages[1][0][fin]' => '12:00', 'duree' => '120', 'prevenance' => '48']);
        self::assertResponseRedirects('/espace-pro/agenda?onglet=horaires', 303);
        $page = $this->client->followRedirect();
        self::assertSame('08:00', $page->filter('#p1-0-d')->attr('value'));
        self::assertCount(1, $page->filter('#p1-1-d'), 'une plage vide de plus');
        self::assertSame('120', $page->filter('#duree option[selected]')->attr('value'));

        // Copie du lundi sur les jours ouvrés.
        $this->client->request('POST', '/espace-pro/agenda/horaires', ['_token' => (string) $page->filter('form#horaires input[name="_token"]')->attr('value'), 'plages' => [1 => [['debut' => '08:00', 'fin' => '12:00']]], 'copier' => '1', 'duree' => '120', 'prevenance' => '48']);
        self::assertSame(5, DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM artisan.availability_rule WHERE artisan_id = :a', ['a' => $this->artisan])));

        $planning = $this->client->request('GET', '/espace-pro/agenda?date=2030-01-07');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Semaine du 7 au 13 janv. 2030', $planning->filter('.tm-agenda__period')->text());
        $open = $planning->filter('.tm-agenda__week .tm-agenda__slot.is-open');
        // Au-delà de l'horizon de 60 jours : aucun créneau généré, la grille montre les jours sans créneau.
        self::assertCount(0, $open);

        // Semaine prochaine : la semaine en cours peut ne plus avoir de créneau réservable (fin de semaine, prévenance 48 h).
        $planning = $this->client->request('GET', '/espace-pro/agenda?date='.new \DateTimeImmutable('monday next week')->format('Y-m-d'));
        self::assertGreaterThan(0, $planning->filter('.tm-agenda__week .tm-agenda__slot.is-open')->count() + $planning->filter('.tm-agenda__week .tm-agenda__slot.is-closed')->count());
        self::assertStringContainsString('Disponible 08:00 – 12:00', $this->client->request('GET', '/espace-pro/agenda?vue=liste&date='.new \DateTimeImmutable('monday next week')->format('Y-m-d'))->filter('.tm-agenda__list')->text());
        self::assertCount(1, $this->client->request('GET', '/espace-pro/agenda?vue=mois')->filter('table.tm-agenda__month'));
    }

    public function testAbsencesHolidaysAndPause(): void
    {
        $this->client->request('GET', '/espace-pro/agenda?onglet=horaires');
        $this->client->submit($this->client->getCrawler()->filter('form#horaires')->form(), ['plages[2][0][debut]' => '09:00', 'plages[2][0][fin]' => '11:00']);
        $tuesday = new \DateTimeImmutable('tuesday next week');

        $page = $this->client->request('GET', '/espace-pro/agenda?onglet=indisponibilites');
        $this->client->submit($page->filter('#ajout-absence')->form(), ['debut' => $tuesday->format('Y-m-d'), 'type' => 'leave', 'libelle' => 'Congés (fictif)']);
        $page = $this->client->followRedirect();
        self::assertStringContainsString('Congés (fictif)', $page->filter('.tm-agenda__absences')->text());
        self::assertSame(0, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM artisan.availability_slot WHERE artisan_id = :a AND (starts_at AT TIME ZONE 'Europe/Paris')::date = :d", ['a' => $this->artisan, 'd' => $tuesday->format('Y-m-d')])));
        $week = $this->client->request('GET', '/espace-pro/agenda?date='.$tuesday->format('Y-m-d'));
        self::assertStringContainsString('Congés (fictif)', $week->filter('.tm-agenda__week')->text());

        $this->client->submit($this->client->request('GET', '/espace-pro/agenda?onglet=indisponibilites')->filter('.tm-agenda__absences ul form')->form());
        self::assertSame(2, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM artisan.availability_slot WHERE artisan_id = :a AND (starts_at AT TIME ZONE 'Europe/Paris')::date = :d", ['a' => $this->artisan, 'd' => $tuesday->format('Y-m-d')])), 'créneaux rouverts');

        $page = $this->client->request('GET', '/espace-pro/agenda?onglet=feries');
        self::assertStringContainsString('Aucun créneau n’est ouvert les jours fériés', $page->filter('.tm-agenda__holidays')->text());
        $this->client->submit($page->selectButton('Travailler les jours fériés')->form());
        self::assertFalse($this->connection->fetchOne('SELECT closed_on_holidays FROM artisan.agenda_settings WHERE artisan_id = :a', ['a' => $this->artisan]));

        $page = $this->client->request('GET', '/espace-pro/agenda');
        self::assertSame('true', $page->filter('.tm-agenda__toggle')->attr('aria-checked'));
        $this->client->submit($page->filter('.tm-agenda__status form')->form());
        $page = $this->client->followRedirect();
        self::assertSame('false', $page->filter('.tm-agenda__toggle')->attr('aria-checked'));
        self::assertStringContainsString('Mode pause', $page->filter('.tm-agenda__next')->text());
    }

    public function testPrivateIcalLinkBadgeAndPause(): void
    {
        $this->client->request('GET', '/espace-pro/agenda?onglet=horaires');
        $token = (string) $this->client->getCrawler()->filter('form#horaires input[name="_token"]')->attr('value');
        $this->client->request('POST', '/espace-pro/agenda/horaires', ['_token' => $token, 'plages' => [1 => [['debut' => '08:00', 'fin' => '12:00']]], 'copier' => '1', 'duree' => '60', 'prevenance' => '24']);
        $this->client->request('GET', '/espace-pro/agenda?onglet=indisponibilites');
        $day = new \DateTimeImmutable('+10 days');
        $this->client->submit($this->client->getCrawler()->filter('#ajout-absence')->form(), ['debut' => $day->format('Y-m-d'), 'type' => 'leave', 'libelle' => 'Formation, Rouen (fictif)']);

        // RG-013 : badge « Disponible » (créneau sous 7 jours, prévenance comprise), retiré en mode pause.
        $queries = self::getContainer()->get(QueryBus::class);
        \assert($queries instanceof QueryBus);
        $artisan = $queries->ask(new SearchableArtisanOf($this->artisan));
        self::assertInstanceOf(SearchableArtisan::class, $artisan);
        self::assertTrue($artisan->availableSoon);

        // Lien iCal : montré une fois, révocable.
        $page = $this->client->request('GET', '/espace-pro/agenda/synchronisation');
        self::assertStringContainsString('Aucun lien actif', $page->filter('.tm-agenda__sync')->text());
        $this->client->submit($page->selectButton('Créer mon lien iCal')->form());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $url = (string) $this->client->getCrawler()->filter('#ical-url')->attr('value');
        self::assertMatchesRegularExpression('#/agenda/[0-9a-f]{48}\.ics$#', $url);
        self::assertSame(1, DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM artisan.agenda_settings WHERE ical_token_hash = :h', ['h' => hash('sha256', (string) preg_replace('#^.*/agenda/([0-9a-f]{48})\.ics$#', '$1', $url))])), 'seule l’empreinte est conservée');
        $path = (string) parse_url($url, \PHP_URL_PATH);

        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/calendar', (string) $this->client->getResponse()->headers->get('Content-Type'));
        $ics = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        self::assertStringContainsString('SUMMARY:Formation\, Rouen (fictif) (TrouveMoi)', $ics);
        self::assertStringContainsString('DTSTART;VALUE=DATE:'.$day->format('Ymd'), $ics);
        self::assertStringNotContainsString('Atelier Fictif@', $ics);

        $page = $this->client->request('GET', '/espace-pro/agenda/synchronisation');
        self::assertStringContainsString('Lien privé actif depuis', $page->filter('.tm-agenda__sync')->text());
        self::assertCount(0, $page->filter('#ical-url'), 'jamais réaffiché');
        $this->client->submit($page->selectButton('Révoquer le lien')->form());
        $this->client->request('GET', $path);
        self::assertResponseStatusCodeSame(404);

        // Mode pause : ni badge ni demande directe.
        $page = $this->client->request('GET', '/espace-pro/agenda');
        $this->client->submit($page->filter('.tm-agenda__status form')->form());
        $artisan = $queries->ask(new SearchableArtisanOf($this->artisan));
        self::assertInstanceOf(SearchableArtisan::class, $artisan);
        self::assertFalse($artisan->availableSoon);
        $profile = $queries->ask(new PublicArtisanProfile($this->artisan));
        self::assertInstanceOf(PublicProfileView::class, $profile);
        self::assertFalse($profile->acceptsDirectRequests);
        $this->client->request('GET', '/pro/'.$this->artisan);
        $fiche = $this->client->followRedirect();
        self::assertStringContainsStringIgnoringCase('ne prend pas de nouvelles demandes', $fiche->filter('.tm-profile__actions')->text());
        self::assertCount(0, $fiche->filter('.tm-profile a[href*="pro="], .tm-profile input[name="pro"]'));
    }

    public function testLeavePageListsAndRemovesLeave(): void
    {
        $page = $this->client->request('GET', '/espace-pro/conges');
        self::assertResponseIsSuccessful();
        self::assertSame('Mes congés', $page->filter('h1')->text());
        self::assertStringContainsString('Aucun congé à venir', $page->filter('.tm-leave__table')->text());

        $from = new \DateTimeImmutable('monday next week');
        $this->client->submit($page->filter('#declarer')->form(), ['debut' => $from->format('Y-m-d'), 'fin' => $from->modify('+4 days')->format('Y-m-d'), 'type' => 'leave', 'libelle' => 'Vacances (fictif)']);
        self::assertResponseRedirects('/espace-pro/conges', 303);
        $page = $this->client->followRedirect();
        self::assertStringContainsString('Vacances (fictif)', $page->filter('.tm-leave__table tbody')->text());
        self::assertStringContainsString('5 jours', $page->filter('.tm-leave__table tbody')->text());
        self::assertStringContainsString('1', $page->filter('.tm-agenda__kpis .is-orange strong')->text());
        $week = $this->client->request('GET', '/espace-pro/conges?date='.$from->format('Y-m-d'));
        self::assertStringContainsString('Vacances (fictif)', $week->filter('.tm-agenda__week')->text());

        $this->connection->executeStatement("INSERT INTO artisan.availability_absence (id, artisan_id, starts_on, ends_on, kind, label, created_at) VALUES ('0190f5e4-0000-7000-8000-0000000ae7aa', :a, current_date - 20, current_date - 18, 'leave', 'Pont (fictif)', now())", ['a' => $this->artisan]);
        $history = $this->client->request('GET', '/espace-pro/conges?onglet=historique');
        self::assertStringContainsString('Pont (fictif)', $history->filter('.tm-leave__table')->text());
        self::assertCount(0, $history->filter('.tm-leave__table tbody form'), 'pas de suppression du passé');

        $page = $this->client->request('GET', '/espace-pro/conges');
        $this->client->submit($page->filter('.tm-leave__table tbody form')->form());
        self::assertResponseRedirects('/espace-pro/conges', 303);
        self::assertStringContainsString('Aucun congé à venir', $this->client->followRedirect()->filter('.tm-leave__table')->text());
        self::assertCount(1, $this->client->request('GET', '/espace-pro/conges?onglet=feries')->filter('.tm-agenda__holiday-list'));
    }
}
