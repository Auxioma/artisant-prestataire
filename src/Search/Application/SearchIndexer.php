<?php

declare(strict_types=1);

namespace App\Search\Application;

use App\Catalog\Application\Geo\Query\CommuneIndexEntries;
use App\Catalog\Application\Geo\Query\CommuneSummary;
use App\Catalog\Application\Query\JobIndexEntries;
use App\Catalog\Application\Query\JobIndexEntry;
use App\Search\Application\Port\AutocompleteIndex;
use App\Search\Application\Port\JobDocument;
use App\Search\Application\Port\PlaceDocument;
use App\Shared\Application\Bus\QueryBus;

/** Alimente l'index d'autocomplétion depuis le référentiel (source de vérité : PostgreSQL). */
final readonly class SearchIndexer
{
    public function __construct(private AutocompleteIndex $index, private QueryBus $queries)
    {
    }

    /** @return array{jobs: int, places: int} */
    public function reindexAll(): array
    {
        $jobs = array_values(array_filter($this->jobEntries(null), static fn (JobIndexEntry $e): bool => $e->searchable));
        $this->index->replaceJobs(array_map(self::jobDocument(...), $jobs));

        $communes = $this->queries->ask(new CommuneIndexEntries());
        $places = [];
        foreach (\is_array($communes) ? $communes : [] as $commune) {
            if ($commune instanceof CommuneSummary) {
                $places[] = new PlaceDocument($commune->insee, $commune->name, $commune->slug, $commune->postalCodes, $commune->departmentCode, $commune->departmentName ?? '', $commune->population ?? 0);
            }
        }
        $this->index->replacePlaces($places);

        return ['jobs' => \count($jobs), 'places' => \count($places)];
    }

    /**
     * Met à jour les métiers indiqués (null = tous) : indexés s'ils sont recherchables, retirés sinon.
     *
     * @param list<string>|null $jobIds
     */
    public function reindexJobs(?array $jobIds): void
    {
        $entries = $this->jobEntries($jobIds);
        $searchable = array_values(array_filter($entries, static fn (JobIndexEntry $e): bool => $e->searchable));
        $removed = array_values(array_map(static fn (JobIndexEntry $e): string => $e->id, array_filter($entries, static fn (JobIndexEntry $e): bool => !$e->searchable)));
        $this->index->updateJobs(array_map(self::jobDocument(...), $searchable), $removed);
    }

    /**
     * @param list<string>|null $jobIds
     *
     * @return list<JobIndexEntry>
     */
    private function jobEntries(?array $jobIds): array
    {
        $entries = $this->queries->ask(new JobIndexEntries($jobIds));

        return array_values(array_filter(\is_array($entries) ? $entries : [], static fn (mixed $e): bool => $e instanceof JobIndexEntry));
    }

    private static function jobDocument(JobIndexEntry $entry): JobDocument
    {
        return new JobDocument($entry->id, $entry->name, $entry->slug, $entry->synonyms, $entry->categoryName, $entry->categorySlug);
    }
}
