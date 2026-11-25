<?php

declare(strict_types=1);

namespace App\Search\Application\Query;

use App\Catalog\Application\Geo\Query\CommuneSummary;
use App\Catalog\Application\Geo\Query\FindCommunes;
use App\Catalog\Application\Query\FindJobs;
use App\Catalog\Application\Query\JobIndexEntry;
use App\Search\Application\Port\AutocompleteIndex;
use App\Search\Application\Port\JobDocument;
use App\Search\Application\Port\PlaceDocument;
use App\Search\Application\Port\SearchUnavailable;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Bus\QueryHandler;

/**
 * Autocomplétion (RG-010, P01.2) : moteur plein texte (tolérance aux fautes), repli PostgreSQL si indisponible.
 */
final readonly class AutocompleteHandler implements QueryHandler
{
    public function __construct(
        private AutocompleteIndex $index,
        private QueryBus $queries,
    ) {
    }

    public function __invoke(Autocomplete $query): Suggestions
    {
        $term = trim((string) preg_replace('/\s+/u', ' ', $query->term));
        if (mb_strlen($term) < Autocomplete::MIN_LENGTH) {
            return new Suggestions([], [], false);
        }
        $term = mb_substr($term, 0, Autocomplete::MAX_LENGTH);
        $limit = Autocomplete::MAX_SUGGESTIONS;

        // Code postal saisi : correspondance exacte par préfixe (la tolérance aux fautes proposerait 17000 pour 14000).
        if (Autocomplete::SCOPE_PLACES === $query->scope && 1 === preg_match('/^\d+$/', $term)) {
            return new Suggestions([], $this->fallbackPlaces($term, $limit), false);
        }

        try {
            return Autocomplete::SCOPE_PLACES === $query->scope
                ? new Suggestions([], $this->index->suggestPlaces($term, $limit), false)
                : new Suggestions($this->index->suggestJobs($term, $limit), [], false);
        } catch (SearchUnavailable) {
            // Panne journalisée par l'adaptateur ; réponse dégradée signalée à l'appelant.
        }

        return Autocomplete::SCOPE_PLACES === $query->scope
            ? new Suggestions([], $this->fallbackPlaces($term, $limit), true)
            : new Suggestions($this->fallbackJobs($term, $limit), [], true);
    }

    /** @return list<JobDocument> */
    private function fallbackJobs(string $term, int $limit): array
    {
        $entries = $this->queries->ask(new FindJobs($term, $limit));

        return array_values(array_map(
            static fn (JobIndexEntry $e): JobDocument => new JobDocument($e->id, $e->name, $e->slug, $e->synonyms, $e->categoryName, $e->categorySlug),
            array_filter(\is_array($entries) ? $entries : [], static fn (mixed $e): bool => $e instanceof JobIndexEntry),
        ));
    }

    /** @return list<PlaceDocument> */
    private function fallbackPlaces(string $term, int $limit): array
    {
        $communes = $this->queries->ask(new FindCommunes($term, $limit));

        return array_values(array_map(
            static fn (CommuneSummary $c): PlaceDocument => new PlaceDocument($c->insee, $c->name, $c->slug, $c->postalCodes, $c->departmentCode, $c->departmentName ?? '', $c->population ?? 0),
            array_filter(\is_array($communes) ? $communes : [], static fn (mixed $c): bool => $c instanceof CommuneSummary),
        ));
    }
}
