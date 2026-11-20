<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use App\Catalog\Application\Geo\Query\CommuneSummary;
use App\Catalog\Application\Geo\Query\FindCommunes;
use App\Catalog\Application\Query\FindJobs;
use App\Catalog\Application\Query\JobIndexEntry;
use App\Search\Application\Port\JobDocument;
use App\Search\Application\Query\Autocomplete;
use App\Search\Application\Query\AutocompleteHandler;
use App\Shared\Application\Bus\Query;
use App\Tests\Support\Fake\InMemoryAutocompleteIndex;
use App\Tests\Support\Fake\RecordingQueryBus;
use PHPUnit\Framework\TestCase;

/** RG-010 / P01.2 : moteur plein texte, repli PostgreSQL en cas de panne, codes postaux exacts. */
final class AutocompleteHandlerTest extends TestCase
{
    private InMemoryAutocompleteIndex $index;
    private RecordingQueryBus $queries;
    private AutocompleteHandler $handler;

    protected function setUp(): void
    {
        $this->index = new InMemoryAutocompleteIndex();
        $this->index->replaceJobs([new JobDocument('j1', 'Plombier', 'plombier', [], 'Plomberie', 'plomberie')]);
        $this->queries = new RecordingQueryBus(static fn (Query $query): array => match (true) {
            $query instanceof FindJobs => [new JobIndexEntry('j2', 'Plombier chauffagiste', 'plombier-chauffagiste', ['Chauffagiste'], 'Plomberie', 'plomberie', true)],
            $query instanceof FindCommunes => [new CommuneSummary('14118', 'Caen', 'caen-14000', ['14000'], '14', 109400, null, 'Calvados')],
            default => [],
        });
        $this->handler = new AutocompleteHandler($this->index, $this->queries);
    }

    public function testUsesTheIndexWhenAvailable(): void
    {
        $result = ($this->handler)(new Autocomplete('plomb', Autocomplete::SCOPE_JOBS));

        self::assertFalse($result->degraded);
        self::assertSame(['plombier'], array_map(static fn (JobDocument $j): string => $j->slug, $result->jobs));
        self::assertSame([], $this->queries->asked);
    }

    public function testFallsBackToDatabaseWhenEngineIsDown(): void
    {
        $this->index->unavailable = true;

        $jobs = ($this->handler)(new Autocomplete('plomb', Autocomplete::SCOPE_JOBS));
        $places = ($this->handler)(new Autocomplete('caen', Autocomplete::SCOPE_PLACES));

        self::assertTrue($jobs->degraded);
        self::assertSame('plombier-chauffagiste', $jobs->jobs[0]->slug ?? null);
        self::assertTrue($places->degraded);
        self::assertSame('Calvados', $places->places[0]->departmentName ?? null);
        self::assertInstanceOf(FindJobs::class, $this->queries->asked[0]);
        self::assertSame(Autocomplete::MAX_SUGGESTIONS, $this->queries->asked[0]->limit);
    }

    public function testIgnoresTermsShorterThanTwoCharacters(): void
    {
        $result = ($this->handler)(new Autocomplete("  p \t", Autocomplete::SCOPE_JOBS));

        self::assertSame([], $result->jobs);
        self::assertSame(0, $this->index->searches);
        self::assertSame([], $this->queries->asked);
    }

    public function testPostalCodesUseExactDatabaseLookup(): void
    {
        $result = ($this->handler)(new Autocomplete('14000', Autocomplete::SCOPE_PLACES));

        self::assertFalse($result->degraded);
        self::assertSame('14118', $result->places[0]->insee ?? null);
        self::assertSame(0, $this->index->searches);
        self::assertInstanceOf(FindCommunes::class, $this->queries->asked[0]);
    }

    public function testNormalisesWhitespaceAndTruncatesLongTerms(): void
    {
        $this->index->unavailable = true;
        ($this->handler)(new Autocomplete('  plombier   chauffagiste  '.str_repeat('x', 200), Autocomplete::SCOPE_JOBS));

        self::assertInstanceOf(FindJobs::class, $this->queries->asked[0]);
        self::assertStringStartsWith('plombier chauffagiste x', $this->queries->asked[0]->term);
        self::assertSame(Autocomplete::MAX_LENGTH, mb_strlen($this->queries->asked[0]->term));
    }
}
