<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Application\Geo\CommuneRecord;
use App\Catalog\Application\Geo\CommuneRow;
use App\Catalog\Application\Geo\DepartmentRecord;
use App\Catalog\Application\Geo\GeoReferentialImporter;
use App\Catalog\Application\Geo\Port\GeoReferentialSource;
use App\Catalog\Application\Geo\Port\GeoReferentialStore;
use App\Catalog\Application\Geo\RegionRecord;
use App\Shared\Application\Transaction\TransactionManager;
use App\Shared\Domain\Exception\InvalidValue;
use App\Shared\Infrastructure\Identifier\UuidV7Generator;
use App\Tests\Support\Fake\FixedClock;
use App\Tests\Support\Fake\InMemoryAuditTrail;
use PHPUnit\Framework\TestCase;

/** BO07.1 : import du référentiel géographique — validation, slugs stables, garde-fous. */
final class GeoReferentialImporterTest extends TestCase
{
    private InMemoryGeoStore $store;
    private InMemoryAuditTrail $audit;
    private GeoReferentialImporter $importer;

    protected function setUp(): void
    {
        $this->store = new InMemoryGeoStore();
        $this->audit = new InMemoryAuditTrail();
        $transactions = new class implements TransactionManager {
            public function run(callable $operation): mixed
            {
                return $operation();
            }
        };
        $this->importer = new GeoReferentialImporter($this->store, $transactions, $this->audit, new UuidV7Generator(), new FixedClock());
    }

    /**
     * @param list<CommuneRecord> $communes
     */
    private function source(array $communes): GeoReferentialSource
    {
        return new class($communes) implements GeoReferentialSource {
            /** @param list<CommuneRecord> $communes */
            public function __construct(private array $communes)
            {
            }

            public function regions(): iterable
            {
                return [new RegionRecord('28', 'Normandie')];
            }

            public function departments(): iterable
            {
                return [new DepartmentRecord('76', 'Seine-Maritime', '28'), new DepartmentRecord('14', 'Calvados', '28')];
            }

            public function communes(): iterable
            {
                return $this->communes;
            }
        };
    }

    /** @param list<string> $postalCodes */
    private static function commune(string $insee, string $name, array $postalCodes, string $department = '76', float $lat = 49.5, float $lng = 0.1): CommuneRecord
    {
        return new CommuneRecord($insee, $name, $postalCodes, $department, 1000, $lat, $lng);
    }

    public function testSlugUsesSmallestPostalCode(): void
    {
        $this->importer->import($this->source([self::commune('76351', 'Le Havre', ['76620', '76600', '76610'])]), false);

        self::assertSame('le-havre-76600', $this->store->row('76351')->slug, 'Annexe A : /{metier}/{ville-cp}, ex. /plombier/le-havre-76600');
        self::assertSame(['76600', '76610', '76620'], $this->store->row('76351')->postalCodes);
    }

    public function testHomonymWithSamePostalCodeFallsBackToInsee(): void
    {
        $this->importer->import($this->source([self::commune('14001', 'Saint-Martin', ['14100'], '14'), self::commune('14002', 'Saint-Martin', ['14100'], '14')]), false);

        self::assertSame('saint-martin-14100', $this->store->row('14001')->slug);
        self::assertSame('saint-martin-14002', $this->store->row('14002')->slug);
    }

    public function testCommuneWithoutPostalCodeUsesInsee(): void
    {
        $this->importer->import($this->source([self::commune('14999', 'Fleury-devant-Douaumont', [], '14')]), false);

        self::assertSame('fleury-devant-douaumont-14999', $this->store->row('14999')->slug);
    }

    public function testExistingSlugIsNeverChanged(): void
    {
        $this->importer->import($this->source([self::commune('76351', 'Le Havre', ['76600'])]), false);
        $this->importer->import($this->source([self::commune('76351', 'Le Havre', ['76550'])]), false);

        self::assertSame('le-havre-76600', $this->store->row('76351')->slug, 'URL publiques stables');
        self::assertSame(['76550'], $this->store->row('76351')->postalCodes, 'Données mises à jour');
    }

    public function testInvalidRecordsAreRejectedAndOverseasCollectivitiesExcluded(): void
    {
        $report = $this->importer->import($this->source([
            self::commune('76351', 'Le Havre', ['76600']),
            self::commune('76999', 'Hors bornes', ['76999'], '76', 200.0),
            self::commune('ABCDE', 'Code faux', ['76000']),
            self::commune('27229', 'Évreux', ['27000'], '27'),
            self::commune('97502', 'Saint-Pierre', ['97500'], '975'),
        ]), false);

        self::assertSame(1, $report->communes);
        self::assertCount(3, $report->rejected);
        self::assertStringContainsString('département inconnu', implode(' ', $report->rejected));
        self::assertSame(1, $report->outOfScope, 'Collectivité d’outre-mer hors périmètre (P-35)');
        self::assertCount(1, $this->audit->records);
        self::assertSame('catalog.geo_imported', $this->audit->records[0]->action);
    }

    public function testPartialImportCannotDeactivateTheReferential(): void
    {
        $this->importer->import($this->source([self::commune('76351', 'Le Havre', ['76600']), self::commune('76540', 'Rouen', ['76000'])]), false);

        $this->expectException(InvalidValue::class);
        $this->expectExceptionMessage('import partiel');
        $this->importer->import($this->source([self::commune('76351', 'Le Havre', ['76600'])]), true);
    }

    public function testFullImportDeactivatesMissingCommunes(): void
    {
        $all = array_map(static fn (int $i): CommuneRecord => self::commune(\sprintf('76%03d', $i), 'Commune '.$i, [\sprintf('76%03d', $i)]), range(1, 20));
        $this->importer->import($this->source($all), false);

        $report = $this->importer->import($this->source(\array_slice($all, 0, 19)), true);

        self::assertSame(1, $report->deactivated, 'Commune fusionnée désactivée, jamais supprimée');
    }
}

/** Magasin en mémoire respectant le contrat du port (slug conservé à la mise à jour). */
final class InMemoryGeoStore implements GeoReferentialStore
{
    /** @var array<string, CommuneRow> */
    public array $rows = [];
    /** @var array<string, bool> */
    public array $active = [];

    public function row(string $insee): CommuneRow
    {
        return $this->rows[$insee] ?? throw new \LogicException('Commune '.$insee.' absente');
    }

    public function upsertRegions(array $regions): void
    {
    }

    public function upsertDepartments(array $departments): void
    {
    }

    public function upsertCommunes(array $communes): int
    {
        $new = 0;
        foreach ($communes as $row) {
            $existing = $this->rows[$row->insee] ?? null;
            $new += null === $existing ? 1 : 0;
            $this->rows[$row->insee] = null === $existing ? $row : new CommuneRow($row->insee, $row->name, $existing->slug, $row->postalCodes, $row->departmentCode, $row->population, $row->latitude, $row->longitude);
            $this->active[$row->insee] = true;
        }

        return $new;
    }

    public function slugOwners(): array
    {
        $owners = [];
        foreach ($this->rows as $row) {
            $owners[$row->slug] = $row->insee;
        }

        return $owners;
    }

    public function activeCommuneCount(): int
    {
        return \count(array_filter($this->active));
    }

    public function deactivateMissing(array $presentInsee): int
    {
        $count = 0;
        foreach ($this->active as $insee => $active) {
            if ($active && !\in_array((string) $insee, $presentInsee, true)) {
                $this->active[$insee] = false;
                ++$count;
            }
        }

        return $count;
    }

    public function enableSeoPages(int $minPopulation): int
    {
        return 0;
    }
}
