<?php

declare(strict_types=1);

namespace App\Tests\Functional\Content;

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

/** §7 / BO12 : robots.txt, sitemaps segmentés (RG-023), canonical et Open Graph systématiques, données structurées activables. */
final class SeoTest extends WebTestCase
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
        $this->profileId = $this->submittedDossier();
        $this->uploadPiece('kbis');
        $this->uploadPiece('insurance_rc');
        $this->dossierDispatch(new ApproveArtisan($this->profileId, self::ADMIN));
        $project = self::getContainer()->get(ProjectArtisanForSearch::class);
        \assert($project instanceof ProjectArtisanForSearch);
        $project(new ArtisanVerified($this->profileId, self::DOSSIER_USER, self::ADMIN, new \DateTimeImmutable()));
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $connection->executeStatement("UPDATE search.artisan_search_view SET location = (SELECT centroid FROM catalog.commune WHERE insee = '76341')");
        $connection->executeStatement("UPDATE catalog.commune SET seo_page_enabled = true WHERE insee IN ('76341', '76351')");
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    public function testRobotsAndSitemaps(): void
    {
        $this->setting('seo.robots_extra', ['Disallow: /recherche?*tri=']);
        $this->client->request('GET', '/robots.txt');
        $robots = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Disallow: /admin/', $robots);
        self::assertStringContainsString('Disallow: /recherche?*tri=', $robots, 'lignes ajoutées en BO12.3');
        self::assertStringContainsString('Sitemap: http://localhost/sitemap.xml', $robots);

        $this->client->request('GET', '/sitemap.xml');
        self::assertResponseHeaderSame('Content-Type', 'application/xml; charset=UTF-8');
        self::assertSame(4, substr_count((string) $this->client->getResponse()->getContent(), '<sitemap>'));

        $this->client->request('GET', '/sitemaps/artisans-1.xml');
        self::assertStringContainsString('<loc>http://localhost/pro/atelier-fictif-caen-'.$this->profileId.'</loc>', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/sitemaps/metiers-1.xml');
        self::assertStringContainsString('<loc>http://localhost/metiers/plombier</loc>', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/sitemaps/pages-1.xml');
        self::assertStringContainsString('<loc>http://localhost/cgu</loc>', (string) $this->client->getResponse()->getContent(), 'documents légaux publiés');

        $this->client->request('GET', '/sitemaps/villes-1.xml');
        self::assertStringNotContainsString('/plombier/harfleur-76700', (string) $this->client->getResponse()->getContent(), 'RG-023 : 1 professionnel < 3');
        $this->setting('seo.min_pros_index', 1);
        $sitemaps = self::getContainer()->get('cache.sitemap');
        \assert($sitemaps instanceof CacheInterface);
        $sitemaps->delete('sitemap.villes');
        $this->client->request('GET', '/sitemaps/villes-1.xml');
        $villes = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<loc>http://localhost/plombier/harfleur-76700</loc>', $villes);
        self::assertStringContainsString('<loc>http://localhost/artisans/harfleur-76700</loc>', $villes);
        self::assertStringContainsString('<loc>http://localhost/artisans/departement/14</loc>', $villes, 'siège à Caen');
    }

    public function testCanonicalOpenGraphAndStructuredDataToggles(): void
    {
        $crawler = $this->client->request('GET', '/metiers/plombier?utm_source=test');
        self::assertSame('http://localhost/metiers/plombier', $crawler->filter('link[rel="canonical"]')->attr('href'), 'canonical sans paramètres');
        self::assertSame('http://localhost/metiers/plombier', $crawler->filter('meta[property="og:url"]')->attr('content'));
        self::assertStringContainsString('Plombier', (string) $crawler->filter('meta[property="og:title"]')->attr('content'));
        self::assertNotEmpty($crawler->filter('meta[name="description"]')->attr('content'));
        self::assertCount(1, $crawler->filter('link[rel="canonical"]'), 'une seule balise canonical');
        self::assertStringContainsString('FAQPage', $crawler->filter('script[type="application/ld+json"]')->last()->text());

        $crawler = $this->client->request('GET', '/pro/atelier-fictif-caen-'.$this->profileId);
        self::assertSame('profile', $crawler->filter('meta[property="og:type"]')->attr('content'));

        $this->setting('seo.schema_faq', false);
        $this->setting('seo.schema_breadcrumb', false);
        $crawler = $this->client->request('GET', '/metiers/plombier');
        self::assertCount(0, $crawler->filter('script[type="application/ld+json"]'), 'FAQPage et BreadcrumbList désactivés (BO12.4)');

        $crawler = $this->client->request('GET', '/');
        self::assertStringContainsString('"@type":"Organization"', $crawler->filter('script[type="application/ld+json"]')->text());
    }

    private function setting(string $key, mixed $value): void
    {
        $this->dossierDispatch(new ChangeSetting($key, $value, self::ADMIN, 'Test SEO'));
        $cache = self::getContainer()->get('cache.settings');
        $settings = self::getContainer()->get(DbalSettingsProvider::class);
        \assert($cache instanceof CacheInterface && $settings instanceof DbalSettingsProvider);
        $cache->delete(DbalSettingsProvider::CACHE_KEY);
        $settings->reset();
    }
}
