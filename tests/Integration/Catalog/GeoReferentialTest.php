<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Application\Geo\GeoReferentialImporter;
use App\Catalog\Application\Geo\Port\GeoReferentialSourceFactory;
use App\Catalog\Application\Geo\Port\GeoReferentialStore;
use App\Catalog\Application\Geo\Query\CommunesNear;
use App\Catalog\Application\Geo\Query\CommuneSummary;
use App\Catalog\Application\Geo\Query\FindCommunes;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** BO07 / ADR-0004 : import PostGIS, recherche par rayon, recherche sans accents et par code postal. */
final class GeoReferentialTest extends KernelTestCase
{
    private const string FIXTURE = __DIR__.'/../../fixtures/geo/normandie.json';

    private QueryBus $queries;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $importer = $container->get(GeoReferentialImporter::class);
        $sources = $container->get(GeoReferentialSourceFactory::class);
        $queries = $container->get(QueryBus::class);
        $connection = $container->get(Connection::class);
        \assert($importer instanceof GeoReferentialImporter && $sources instanceof GeoReferentialSourceFactory && $queries instanceof QueryBus && $connection instanceof Connection);
        $this->queries = $queries;
        $this->connection = $connection;
        $importer->import($sources->file(self::FIXTURE), false);
    }

    /** @return list<CommuneSummary> */
    private function ask(CommunesNear|FindCommunes $query): array
    {
        $result = $this->queries->ask($query);
        self::assertIsArray($result);

        return array_values(array_filter($result, static fn (mixed $c): bool => $c instanceof CommuneSummary));
    }

    public function testImportStoresCentroidsAndSkipsInvalidOrOutOfScope(): void
    {
        self::assertSame(6, DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM catalog.commune')), 'Évreux (département absent), COM et coordonnées fausses écartés');
        self::assertSame('le-havre-76600', DbalValue::string($this->connection->fetchOne("SELECT slug FROM catalog.commune WHERE insee = '76351'")));
        self::assertSame('fleury-devant-douaumont-14999', DbalValue::string($this->connection->fetchOne("SELECT slug FROM catalog.commune WHERE insee = '14999'")));
        self::assertEqualsWithDelta(49.4958, (float) DbalValue::string($this->connection->fetchOne("SELECT ST_Y(centroid::geometry)::text FROM catalog.commune WHERE insee = '76351'")), 0.0001);
    }

    public function testCommunesNearUsesGeodesicDistance(): void
    {
        $around = $this->ask(new CommunesNear(49.4958, 0.1312, 60));

        $insee = array_map(static fn (CommuneSummary $c): string => $c->insee, $around);
        self::assertSame(['76351', '76341'], \array_slice($insee, 0, 2), 'Triées par distance : Le Havre puis Harfleur');
        self::assertEqualsCanonicalizing(['76351', '76341', '14118', '14999'], $insee, 'Rouen (~70 km) hors rayon, Caen (~50 km) dedans');
        self::assertSame(0.0, $around[0]->distanceKm);
        $caen = array_values(array_filter($around, static fn (CommuneSummary $c): bool => '14118' === $c->insee))[0];
        self::assertEqualsWithDelta(50.4, $caen->distanceKm ?? 0.0, 1.0);
    }

    public function testFindByNameIgnoresAccentsAndCaseThenRanksByPopulation(): void
    {
        self::assertSame(['le-havre-76600'], array_map(static fn (CommuneSummary $c): string => $c->slug, $this->ask(new FindCommunes('LE HAVRE'))));
        self::assertSame('Caen', $this->ask(new FindCommunes('caén'))[0]->name ?? null);
        self::assertSame([], $this->ask(new FindCommunes('h')), 'Deux caractères minimum (P01.2)');
    }

    public function testFindByPostalCodePrefix(): void
    {
        $result = $this->ask(new FindCommunes('766'));

        self::assertSame('Le Havre', $result[0]->name ?? null);
        self::assertSame(['76600', '76610', '76620'], $result[0]->postalCodes);
    }

    public function testSearchTermIsNeverInterpretedAsSql(): void
    {
        self::assertSame([], $this->ask(new FindCommunes("'; DROP TABLE catalog.commune; --")));
        self::assertSame([], $this->ask(new FindCommunes('%_%')), 'Jokers LIKE échappés');
        self::assertSame(6, DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM catalog.commune')));
    }

    public function testSeoPagesActivationByPopulation(): void
    {
        $store = self::getContainer()->get(GeoReferentialStore::class);
        \assert($store instanceof GeoReferentialStore);

        self::assertSame(4, $store->enableSeoPages(100000), 'Le Havre, Rouen, Caen, Paris');
        self::assertSame(0, $store->enableSeoPages(100000), 'Idempotent');
    }
}
