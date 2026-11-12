<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\ArtisanProfile\Application\Search\SearchableArtisan;
use App\Catalog\Application\Geo\GeoReferentialImporter;
use App\Catalog\Application\Geo\Port\GeoReferentialSourceFactory;
use App\Search\Application\Artisan\ArtisanHit;
use App\Search\Application\Artisan\ArtisanSearchIndex;
use App\Search\Application\Artisan\ArtisanSearchResult;
use App\Search\Application\Artisan\SearchArtisans;
use App\Shared\Application\Bus\QueryBus;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** RG-020 / RG-021 / RG-022 : sélection zone ET rayon, classement, tris, filtres, pagination. */
final class ArtisanSearchTest extends KernelTestCase
{
    private const string HARFLEUR = '76341';
    private const string LE_HAVRE = '76351';
    private const string CAEN = '14118';
    private const string PLOMBIER = '0190f5e4-0000-7000-8000-00000000f001';
    private const string MACON = '0190f5e4-0000-7000-8000-00000000f002';

    private Connection $connection;
    private ArtisanSearchIndex $index;
    private QueryBus $queries;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $connection = $c->get(Connection::class);
        $index = $c->get(ArtisanSearchIndex::class);
        $queries = $c->get(QueryBus::class);
        $importer = $c->get(GeoReferentialImporter::class);
        $sources = $c->get(GeoReferentialSourceFactory::class);
        \assert($connection instanceof Connection && $index instanceof ArtisanSearchIndex && $queries instanceof QueryBus && $importer instanceof GeoReferentialImporter && $sources instanceof GeoReferentialSourceFactory);
        [$this->connection, $this->index, $this->queries] = [$connection, $index, $queries];
        $importer->import($sources->file(__DIR__.'/../../fixtures/geo/normandie.json'), false);
        $this->connection->executeStatement("DELETE FROM admin.setting WHERE key LIKE 'search.%' OR key LIKE 'ranking.%'");
        $cache = $c->get('cache.settings');
        \assert($cache instanceof \Psr\Cache\CacheItemPoolInterface);
        $cache->clear();
    }

    /**
     * Artisan public situé au centroïde de `$commune`, couvrant les communes `$area`.
     *
     * @param list<string> $area
     * @param list<string> $labels
     */
    private function artisan(string $n, string $name, string $commune, array $area, string $mainJob, int $completion, ?float $rating = null, int $reviews = 0, array $labels = []): string
    {
        $id = '0190f5e4-0000-7000-8000-0000000a'.str_pad($n, 4, '0', \STR_PAD_LEFT);
        $this->connection->executeStatement("INSERT INTO artisan.artisan_profile (id, user_id, status, completed_step, jobs, created_at, updated_at) VALUES (:id, :id, 'incomplete', 1, '[]', now(), now())", ['id' => $id]);
        foreach ($area as $insee) {
            $this->connection->executeStatement('INSERT INTO artisan.service_area_commune (artisan_id, insee) VALUES (:id, :insee)', ['id' => $id, 'insee' => $insee]);
        }
        $point = $this->connection->fetchAssociative('SELECT ST_Y(centroid::geometry) AS lat, ST_X(centroid::geometry) AS lng, name, department_code FROM catalog.commune WHERE insee = :i', ['i' => $commune]);
        \assert(\is_array($point));
        $this->index->save(new SearchableArtisan($id, $id, $name, 'Description fictive', null, $mainJob, [$mainJob, self::PLOMBIER === $mainJob ? self::MACON : self::PLOMBIER], $commune, \App\Shared\Infrastructure\Persistence\DbalValue::string($point['name']), \App\Shared\Infrastructure\Persistence\DbalValue::string($point['department_code']), is_numeric($point['lat']) ? (float) $point['lat'] : 0.0, is_numeric($point['lng']) ? (float) $point['lng'] : 0.0, $labels, $completion, new \DateTimeImmutable()));
        if (null !== $rating) {
            $this->connection->executeStatement('UPDATE search.artisan_search_view SET rating_avg = :r, reviews_count = :n WHERE artisan_id = :id', ['r' => $rating, 'n' => $reviews, 'id' => $id]);
        }

        return $id;
    }

    private function search(SearchArtisans $query): ArtisanSearchResult
    {
        $result = $this->queries->ask($query);
        \assert($result instanceof ArtisanSearchResult);

        return $result;
    }

    /** @return list<string> */
    private static function names(ArtisanSearchResult $result): array
    {
        return array_map(static fn (ArtisanHit $h): string => $h->artisan->name, $result->hits);
    }

    public function testOnlyArtisansWhoseZoneContainsTheCommuneAndWithinRadius(): void
    {
        $this->artisan('1', 'Couvre Harfleur', self::LE_HAVRE, [self::HARFLEUR, self::LE_HAVRE], self::PLOMBIER, 80);
        $this->artisan('2', 'Proche mais hors zone', self::LE_HAVRE, [self::LE_HAVRE], self::PLOMBIER, 80);
        $this->artisan('3', 'En zone mais trop loin', self::CAEN, [self::HARFLEUR], self::PLOMBIER, 80);

        $result = $this->search(new SearchArtisans(self::HARFLEUR, 30, self::PLOMBIER));

        self::assertSame(['Couvre Harfleur'], self::names($result), 'RG-021 : zone ET rayon');
        self::assertSame(30, $result->radiusKm);
        self::assertSame(['En zone mais trop loin', 'Couvre Harfleur'], array_reverse(self::names($this->search(new SearchArtisans(self::HARFLEUR, 150, self::PLOMBIER)))), 'Caen (≈ 80 km) entre dans un rayon de 150 km');
    }

    public function testRelevanceRatingAndReviewSorts(): void
    {
        // m fixé (sinon moyenne des seuls artisans du test, ≈ 4,8) : situation de l'exemple du CDC.
        $this->connection->executeStatement("INSERT INTO admin.setting (key, value, type, updated_at) VALUES ('ranking.bayes_m', '4.2', 'nullable_decimal', now())");
        $cache = self::getContainer()->get('cache.settings');
        \assert($cache instanceof \Psr\Cache\CacheItemPoolInterface);
        $cache->clear();
        $this->artisan('1', 'Un seul avis parfait', self::HARFLEUR, [self::HARFLEUR], self::PLOMBIER, 60, 5.0, 1);
        $this->artisan('2', 'Beaucoup d’avis', self::LE_HAVRE, [self::HARFLEUR], self::PLOMBIER, 60, 4.8, 120);
        $this->artisan('3', 'Maçon en complément', self::HARFLEUR, [self::HARFLEUR], self::MACON, 60);

        self::assertSame(['Beaucoup d’avis', 'Un seul avis parfait', 'Maçon en complément'], self::names($this->search(new SearchArtisans(self::HARFLEUR, 30, self::PLOMBIER, SearchArtisans::SORT_RATING))), 'RG-012 : note bayésienne');
        self::assertSame('Beaucoup d’avis', self::names($this->search(new SearchArtisans(self::HARFLEUR, 30, self::PLOMBIER, SearchArtisans::SORT_REVIEWS)))[0]);
        $relevance = $this->search(new SearchArtisans(self::HARFLEUR, 30, self::PLOMBIER));
        self::assertSame('Maçon en complément', self::names($relevance)[2], 'métier secondaire pénalisé (RG-020)');
        self::assertGreaterThan($relevance->hits[2]->score, $relevance->hits[0]->score);
    }

    public function testFiltersAndPagination(): void
    {
        for ($i = 1; $i <= 22; ++$i) {
            $this->artisan((string) $i, \sprintf('Artisan %02d', $i), self::HARFLEUR, [self::HARFLEUR], self::PLOMBIER, $i, null, 0, 1 === $i ? ['RGE QualiPAC'] : []);
        }

        $first = $this->search(new SearchArtisans(self::HARFLEUR, 30, self::PLOMBIER));
        self::assertSame([22, 20, true], [$first->total, \count($first->hits), $first->hasMore()], 'search.page_size = 20');
        self::assertSame('Artisan 22', self::names($first)[0], 'à distance égale, profil le plus complet d’abord');
        self::assertCount(2, $this->search(new SearchArtisans(self::HARFLEUR, 30, self::PLOMBIER, page: 2))->hits);
        self::assertSame(['Artisan 01'], self::names($this->search(new SearchArtisans(self::HARFLEUR, 30, self::PLOMBIER, labels: ['RGE QualiPAC']))));
        self::assertSame(0, $this->search(new SearchArtisans(self::HARFLEUR, 30, self::PLOMBIER, minRating: 4.0))->total, 'note minimale : sans avis, exclu');
        self::assertSame(0, $this->search(new SearchArtisans('99999', 30))->total);
    }
}
