<?php

declare(strict_types=1);

namespace App\Tests\Functional\Search;

use App\Administration\Application\Command\ChangeSetting;
use App\Administration\Infrastructure\Settings\DbalSettingsProvider;
use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\Command\CreateJob;
use App\Shared\Application\Bus\Command;
use App\Shared\Application\Bus\CommandBus;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\Cache\CacheInterface;

/** P06 : annuaire alphabétique, filtres métier / département / initiale, pagination aux URLs propres, bloc artisan. */
final class DirectoryTest extends WebTestCase
{
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a0001';
    /** Artisans fictifs de la projection (une page = 5 avec search.page_size = 5). */
    private const array ARTISANS = [
        ['Atelier Fictif Alpha', '76'], ['Bâti Fictif', '76'], ['Carrelage Fictif', '14'], ['Électricité Fictive', '76'],
        ['Menuiserie Fictive', '14'], ['Plomberie Fictive', '76'], ['3D Rénovation Fictive', '76'],
    ];

    private KernelBrowser $client;
    private string $jobId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->dispatch(new CreateCategory('Plomberie', 'wrench'));
        $this->dispatch(new CreateJob('plomberie', 'Plombier'));
        $jobId = $connection->fetchOne("SELECT id FROM catalog.job WHERE slug = 'plombier'");
        $this->jobId = \is_string($jobId) ? $jobId : '';
        $connection->executeStatement("INSERT INTO catalog.region (code, name, slug) VALUES ('28', 'Normandie', 'normandie') ON CONFLICT DO NOTHING");
        $connection->executeStatement("INSERT INTO catalog.department (code, name, slug, region_code) VALUES ('76', 'Seine-Maritime', 'seine-maritime', '28'), ('14', 'Calvados', 'calvados', '28') ON CONFLICT DO NOTHING");
        foreach (self::ARTISANS as $i => [$name, $department]) {
            $connection->executeStatement(
                "INSERT INTO search.artisan_search_view (artisan_id, user_id, name, job_ids, department_code, commune_name, verified_at, updated_at)
                 VALUES (:id, :user, :name, CAST(:jobs AS uuid[]), :department, 'Commune fictive', date_trunc('second', now()), date_trunc('second', now()))",
                ['id' => \sprintf('0190f5e4-0000-7000-8000-0000000d%04d', $i), 'user' => \sprintf('0190f5e4-0000-7000-8000-0000000e%04d', $i), 'name' => $name,
                    'jobs' => 'Plomberie Fictive' === $name ? '{'.$this->jobId.'}' : '{}', 'department' => $department],
            );
        }
        $this->dispatch(new ChangeSetting('search.page_size', 5, self::ADMIN, 'Test pagination'));
        $cache = self::getContainer()->get('cache.settings');
        $settings = self::getContainer()->get(DbalSettingsProvider::class);
        \assert($cache instanceof CacheInterface && $settings instanceof DbalSettingsProvider);
        $cache->delete(DbalSettingsProvider::CACHE_KEY);
        $settings->reset();
    }

    /** Maquette Koubourath « Nos professionnels » V2 : recherche, engagements, catégories + « Autres services », encart pro. */
    public function testLandingSectionsOnlyOnFirstUnfilteredPage(): void
    {
        $crawler = $this->client->request('GET', '/nos-professionnels');
        self::assertCount(1, $crawler->filter('.tm-directory__search form[role="search"][action="/recherche"]'));
        self::assertCount(5, $crawler->filter('.tm-directory__reassurance li'));
        self::assertGreaterThan(1, $crawler->filter('.tm-directory__category')->count());
        self::assertSame('/categories', $crawler->filter('.tm-directory__category--more')->attr('href'));
        self::assertStringContainsString('Créer mon compte professionnel', $crawler->filter('.tm-directory__pro')->text());

        $page2 = $this->client->request('GET', '/nos-professionnels/page/2');
        self::assertCount(0, $page2->filter('.tm-directory__category'), 'page d’entrée seulement');
        self::assertCount(1, $page2->filter('.tm-directory__pro'), 'encart pro toujours présent (P06.3)');
    }

    public function testAlphabeticalListWithCleanPagination(): void
    {
        $crawler = $this->client->request('GET', '/nos-professionnels');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.tm-search__count', '7 professionnels · page 1 sur 2');
        $names = $crawler->filter('.tm-directory__grid .tm-card-pro__name')->each(static fn ($n): string => trim($n->text()));
        self::assertSame(['3D Rénovation Fictive', 'Atelier Fictif Alpha', 'Bâti Fictif', 'Carrelage Fictif', 'Électricité Fictive'], array_map(static fn (string $n): string => trim(str_replace('Vérifié', '', $n)), $names), 'ordre alphabétique sans accents');
        self::assertSame('/nos-professionnels/page/2', $crawler->filter('.tm-directory__pagination a')->last()->attr('href'));
        self::assertCount(0, $crawler->filter('meta[name="robots"]'), 'annuaire non filtré indexable');
        self::assertSame('directory_view', $crawler->filter('.tm-directory')->attr('data-analytics-auto-event-value'));
        self::assertStringContainsString('100 devis offerts', $crawler->filter('.tm-directory__pro')->text());

        $crawler = $this->client->request('GET', '/nos-professionnels/page/2');
        self::assertResponseIsSuccessful();
        self::assertSame('http://localhost/nos-professionnels/page/2', $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertCount(2, $crawler->filter('.tm-directory__grid .tm-card-pro'));

        $this->client->request('GET', '/nos-professionnels/page/1');
        self::assertResponseRedirects('/nos-professionnels', 301);
        $this->client->request('GET', '/nos-professionnels/page/3');
        self::assertResponseStatusCodeSame(404);
    }

    public function testFilters(): void
    {
        $crawler = $this->client->request('GET', '/nos-professionnels?departement=14');
        self::assertSelectorTextContains('.tm-search__count', '2 professionnels');
        self::assertCount(1, $crawler->filter('meta[name="robots"][content="noindex, follow"]'));
        self::assertSame('14 — Calvados', $crawler->filter('#departement option[selected]')->text(), 'codes numériques conservés comme valeurs');

        $crawler = $this->client->request('GET', '/nos-professionnels?lettre=e');
        self::assertSame('Électricité Fictive', trim(str_replace('Vérifié', '', $crawler->filter('.tm-card-pro__name')->text())), 'initiale sans accent');
        self::assertCount(1, $crawler->filter('.tm-directory__letters span[aria-disabled]:contains("Z")'), 'lettre sans artisan désactivée');

        $crawler = $this->client->request('GET', '/nos-professionnels?lettre=%23');
        self::assertSame('3D Rénovation Fictive', trim(str_replace('Vérifié', '', $crawler->filter('.tm-card-pro__name')->text())));

        $this->client->request('GET', '/nos-professionnels?metier=plombier&departement=76');
        self::assertSelectorTextContains('.tm-search__count', '1 professionnel');
        self::assertSelectorTextContains('.tm-card-pro', 'Plombier');
    }

    private function dispatch(Command $command): void
    {
        $bus = self::getContainer()->get(CommandBus::class);
        \assert($bus instanceof CommandBus);
        $bus->dispatch($command);
    }
}
