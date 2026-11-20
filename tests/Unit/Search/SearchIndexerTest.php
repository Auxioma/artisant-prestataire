<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use App\Catalog\Application\Geo\Query\CommuneIndexEntries;
use App\Catalog\Application\Geo\Query\CommuneSummary;
use App\Catalog\Application\Query\JobIndexEntries;
use App\Catalog\Application\Query\JobIndexEntry;
use App\Catalog\Domain\Event\CategoryChanged;
use App\Catalog\Domain\Event\JobDeactivated;
use App\Search\Application\EventHandler\ReindexJobsOnCatalogChange;
use App\Search\Application\Port\JobDocument;
use App\Search\Application\SearchIndexer;
use App\Shared\Application\Bus\Query;
use App\Tests\Support\Fake\InMemoryAutocompleteIndex;
use App\Tests\Support\Fake\RecordingQueryBus;
use PHPUnit\Framework\TestCase;

/** L'index suit le référentiel : seuls les métiers recherchables (métier et catégories actifs) sont proposés. */
final class SearchIndexerTest extends TestCase
{
    private InMemoryAutocompleteIndex $index;
    private RecordingQueryBus $queries;
    /** @var list<JobIndexEntry> */
    private array $entries;
    private SearchIndexer $indexer;

    protected function setUp(): void
    {
        $this->entries = [
            new JobIndexEntry('j1', 'Plombier', 'plombier', ['Chauffagiste sanitaire'], 'Plomberie', 'plomberie', true),
            new JobIndexEntry('j2', 'Ramoneur', 'ramoneur', [], 'Chauffage', 'chauffage', false),
        ];
        $this->index = new InMemoryAutocompleteIndex();
        $entries = $this->entries;
        $this->queries = new RecordingQueryBus(static function (Query $query) use ($entries): array {
            if ($query instanceof CommuneIndexEntries) {
                return [new CommuneSummary('14118', 'Caen', 'caen-14000', ['14000'], '14', null, null, 'Calvados')];
            }
            \assert($query instanceof JobIndexEntries);

            return null === $query->jobIds ? $entries : array_values(array_filter($entries, static fn (JobIndexEntry $e): bool => \in_array($e->id, $query->jobIds, true)));
        });
        $this->indexer = new SearchIndexer($this->index, $this->queries);
    }

    public function testFullReindexKeepsOnlySearchableJobs(): void
    {
        $counts = $this->indexer->reindexAll();

        self::assertSame(['jobs' => 1, 'places' => 1], $counts);
        self::assertSame(['j1'], array_keys($this->index->jobs));
        self::assertSame(['Chauffagiste sanitaire'], $this->index->jobs['j1']->synonyms);
        $place = array_values($this->index->places)[0] ?? null;
        self::assertNotNull($place);
        self::assertSame(0, $place->population, 'population inconnue → 0 (classement en fin de liste)');
    }

    public function testDeactivationRemovesTheJobFromTheIndex(): void
    {
        $this->index->replaceJobs([new JobDocument('j2', 'Ramoneur', 'ramoneur', [], 'Chauffage', 'chauffage')]);

        new ReindexJobsOnCatalogChange($this->indexer)(new JobDeactivated('j2', null, new \DateTimeImmutable()));

        self::assertSame([], $this->index->jobs);
        $query = $this->queries->asked[0] ?? null;
        self::assertInstanceOf(JobIndexEntries::class, $query);
        self::assertSame(['j2'], $query->jobIds);
    }

    public function testCategoryChangeReindexesEveryJob(): void
    {
        new ReindexJobsOnCatalogChange($this->indexer)(new CategoryChanged('c1', 'name', new \DateTimeImmutable()));

        $query = $this->queries->asked[0] ?? null;
        self::assertInstanceOf(JobIndexEntries::class, $query);
        self::assertNull($query->jobIds);
        self::assertSame(['j1'], array_keys($this->index->jobs));
    }
}
