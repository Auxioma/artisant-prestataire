<?php

declare(strict_types=1);

namespace App\Tests\Functional\Search;

use App\ArtisanProfile\Application\Search\SearchableArtisan;
use App\ArtisanProfile\Application\Search\SearchableArtisanOf;
use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Identity\Application\Command\RegisterUser;
use App\Search\Application\Artisan\ArtisanSearchIndex;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** T-705a — C05 « Mes favoris » : ajout depuis la fiche P10, liste, filtres, artisan devenu inactif grisé, retrait. */
final class ClientFavoritesTest extends WebTestCase
{
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000f7501';
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a7501';

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
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.c05@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Fictive'));
        $connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id IN (:a, :b)", ['a' => self::DOSSIER_USER, 'b' => self::CLIENT]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $queries = self::getContainer()->get(QueryBus::class);
        $index = self::getContainer()->get(ArtisanSearchIndex::class);
        \assert($queries instanceof QueryBus && $index instanceof ArtisanSearchIndex);
        $artisan = $queries->ask(new SearchableArtisanOf($this->artisan));
        \assert($artisan instanceof SearchableArtisan);
        $index->save($artisan);
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->request('POST', '/connexion', ['_username' => 'camille.c05@exemple.test', '_password' => 'cheval agrafe batterie', '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value')]);
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    public function testAddFromProfileListFilterAndRemove(): void
    {
        $page = $this->client->request('GET', '/espace-client/favoris');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Aucun favori pour le moment', $page->filter('.tm-fav')->text());

        $this->client->request('GET', '/pro/'.$this->artisan);
        $profile = $this->client->followRedirect();
        $this->client->submit($profile->selectButton('Ajouter à mes favoris')->form());
        $profile = $this->client->followRedirect();
        self::assertCount(1, $profile->filter('button[aria-pressed="true"]:contains("Retirer de mes favoris")'));

        $page = $this->client->request('GET', '/espace-client/favoris');
        self::assertSame('Mes favoris (1)', $page->filter('h1')->text());
        self::assertStringContainsString('Atelier Fictif', $page->filter('.tm-fav__list')->text());
        self::assertCount(0, $this->client->request('GET', '/espace-client/favoris?q=jardin')->filter('.tm-fav__item'));

        // Artisan devenu inactif (masqué) : listé grisé.
        $this->connection->executeStatement('DELETE FROM search.artisan_search_view WHERE artisan_id = :a', ['a' => $this->artisan]);
        $page = $this->client->request('GET', '/espace-client/favoris');
        self::assertStringContainsString('Plus disponible sur TrouveMoi', $page->filter('.tm-fav__item.is-inactive')->text());

        $this->client->submit($page->filter('.tm-fav__item form')->form());
        self::assertSame(0, DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM search.favorite')));
    }
}
