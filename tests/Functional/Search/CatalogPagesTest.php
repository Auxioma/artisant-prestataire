<?php

declare(strict_types=1);

namespace App\Tests\Functional\Search;

use App\Administration\Application\Command\ChangeSetting;
use App\Administration\Infrastructure\Settings\DbalSettingsProvider;
use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanVerified;
use App\Search\Application\EventHandler\ProjectArtisanForSearch;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\Cache\CacheInterface;

/** P03, P04, P05 : grille et filtre, page catégorie (RG-011), pages métier, métier × ville (RG-023), ville, département. */
final class CatalogPagesTest extends WebTestCase
{
    use ArtisanDossier;

    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a0001';

    private KernelBrowser $client;
    private string $profileId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->prepareReferentials();
        // Artisan vérifié, siège à Caen, zone de 30 km autour d'Harfleur.
        $this->profileId = $this->submittedDossier();
        $this->uploadPiece('kbis');
        $this->uploadPiece('insurance_rc');
        $this->dossierDispatch(new ApproveArtisan($this->profileId, self::ADMIN));
        $project = self::getContainer()->get(ProjectArtisanForSearch::class);
        \assert($project instanceof ProjectArtisanForSearch);
        $project(new ArtisanVerified($this->profileId, self::DOSSIER_USER, self::ADMIN, new \DateTimeImmutable()));
        // Position ramenée à Harfleur (le siège fictif est à Caen, hors du rayon par défaut).
        $this->sql("UPDATE search.artisan_search_view SET location = (SELECT centroid FROM catalog.commune WHERE insee = '76341')");
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    public function testCategoryList(): void
    {
        $crawler = $this->client->request('GET', '/categories');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Catégories');
        $card = $crawler->filter('.tm-category-card')->first();
        self::assertStringContainsString('Plomberie', $card->text());
        self::assertStringContainsString('1 professionnel vérifié', $card->text());
        self::assertSame('/metiers/plombier', $card->filter('.tm-category-card__jobs a')->attr('href'));
        self::assertStringContainsString('plombier', (string) $card->attr('data-terms'), 'filtre instantané sur les métiers');
        self::assertSame('category_list_view', $crawler->filter('.tm-catalog')->attr('data-analytics-auto-event-value'));
    }

    public function testCategoryPageFeaturesOnlyEligibleArtisans(): void
    {
        $crawler = $this->client->request('GET', '/categories/plomberie');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Plomberie');
        self::assertStringContainsString('1 professionnel', $crawler->filter('.tm-job-chip')->text());
        self::assertCount(0, $crawler->filter('.tm-card-artisan'), 'RG-011 : profil complet à moins de 80 %');

        $this->sql("UPDATE search.artisan_search_view SET completion = 90 WHERE artisan_id = '".$this->profileId."'");
        $crawler = $this->client->request('GET', '/categories/plomberie');
        self::assertStringContainsString('Atelier Fictif', $crawler->filter('.tm-card-artisan')->text());
        self::assertSame('/pro/atelier-fictif-caen-'.$this->profileId, $crawler->filter('.tm-card-artisan a')->attr('href'));

        $this->client->request('GET', '/categories/inconnue');
        self::assertResponseStatusCodeSame(404);
    }

    public function testJobPage(): void
    {
        $crawler = $this->client->request('GET', '/metiers/plombier');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Plombier');
        self::assertSame('http://localhost/metiers/plombier', $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertCount(0, $crawler->filter('meta[name="robots"]'));
        self::assertStringContainsString('FAQPage', $crawler->filter('script[type="application/ld+json"]')->last()->text());
        $this->client->request('GET', '/metiers/inconnu');
        self::assertResponseStatusCodeSame(404);
    }

    public function testJobCityPageFollowsIndexingThreshold(): void
    {
        $this->client->request('GET', '/plombier/harfleur-76700');
        self::assertResponseStatusCodeSame(404, 'page ville non activée (BO07.1)');

        $this->sql("UPDATE catalog.commune SET seo_page_enabled = true WHERE insee IN ('76341', '76351')");
        $crawler = $this->client->request('GET', '/plombier/harfleur-76700');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Plombier à Harfleur (76700)');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, follow', 'RG-023 : 1 professionnel < 3');
        self::assertStringContainsString('Atelier Fictif', $crawler->filter('.tm-card-row')->text());
        self::assertSame('/plombier/le-havre-76600', $crawler->filter('.tm-catalog__links a')->first()->attr('href'), 'maillage vers les villes voisines');
        self::assertStringContainsString('"indexable":false', (string) $crawler->filter('.tm-catalog')->attr('data-analytics-auto-params-value'));

        $this->dossierDispatch(new ChangeSetting('seo.min_pros_index', 1, self::ADMIN, 'Test RG-023'));
        // L'invalidation suit l'événement SettingChanged (traité en asynchrone) : simulée ici.
        $cache = self::getContainer()->get('cache.settings');
        $settings = self::getContainer()->get(DbalSettingsProvider::class);
        \assert($cache instanceof CacheInterface && $settings instanceof DbalSettingsProvider);
        $cache->delete(DbalSettingsProvider::CACHE_KEY);
        $settings->reset();
        $crawler = $this->client->request('GET', '/plombier/harfleur-76700');
        // En debug, Symfony ajoute « noindex » à toute réponse (disallow_search_engine_index) : seule la valeur de la page compte.
        self::assertNotSame('noindex, follow', $this->client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertCount(0, $crawler->filter('meta[name="robots"]'));
    }

    public function testCityAndDepartmentPages(): void
    {
        $this->sql("UPDATE catalog.commune SET seo_page_enabled = true WHERE insee = '76351'");
        $crawler = $this->client->request('GET', '/artisans/le-havre-76600');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Artisans et prestataires au Havre (76600)');
        self::assertSame('/plombier/le-havre-76600', $crawler->filter('.tm-catalog__links a[href^="/plombier/"]')->attr('href'), 'métiers disponibles dans la ville');

        $this->client->request('GET', '/artisans/departement/76');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Seine-Maritime (76)');
        $this->client->request('GET', '/artisans/departement/99');
        self::assertResponseStatusCodeSame(404);
    }

    private function sql(string $statement): void
    {
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $connection->executeStatement($statement);
    }
}
