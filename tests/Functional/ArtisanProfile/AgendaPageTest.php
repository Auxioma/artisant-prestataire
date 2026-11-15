<?php

declare(strict_types=1);

namespace App\Tests\Functional\ArtisanProfile;

use App\ArtisanProfile\Application\Verification\ApproveArtisan;
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

        $planning = $this->client->request('GET', '/espace-pro/agenda');
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
}
