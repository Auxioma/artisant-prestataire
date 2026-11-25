<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Search\Application\Port\AutocompleteIndex;
use App\Search\Application\Port\JobDocument;
use App\Search\Application\Port\PlaceDocument;
use App\Search\Application\Port\SearchUnavailable;

/** Index d'autocomplétion en mémoire (recherche naïve par sous-chaîne) ; `unavailable` simule une panne du moteur. */
final class InMemoryAutocompleteIndex implements AutocompleteIndex
{
    public bool $unavailable = false;
    /** @var array<array-key, JobDocument> */
    public array $jobs = [];
    /** @var array<array-key, PlaceDocument> indexé par code INSEE (clé entière en PHP pour les codes numériques) */
    public array $places = [];
    public int $searches = 0;

    public function replaceJobs(array $jobs): void
    {
        $this->guard();
        $this->jobs = [];
        foreach ($jobs as $job) {
            $this->jobs[$job->id] = $job;
        }
    }

    public function replacePlaces(array $places): void
    {
        $this->guard();
        $this->places = [];
        foreach ($places as $place) {
            $this->places[$place->insee] = $place;
        }
    }

    public function updateJobs(array $jobs, array $removedIds): void
    {
        $this->guard();
        foreach ($jobs as $job) {
            $this->jobs[$job->id] = $job;
        }
        foreach ($removedIds as $id) {
            unset($this->jobs[$id]);
        }
    }

    public function suggestJobs(string $term, int $limit): array
    {
        $this->guard();
        ++$this->searches;

        return \array_slice(array_values(array_filter($this->jobs, static fn (JobDocument $j): bool => false !== mb_stripos($j->name, $term))), 0, $limit);
    }

    public function suggestPlaces(string $term, int $limit): array
    {
        $this->guard();
        ++$this->searches;

        return \array_slice(array_values(array_filter($this->places, static fn (PlaceDocument $p): bool => false !== mb_stripos($p->name, $term))), 0, $limit);
    }

    private function guard(): void
    {
        if ($this->unavailable) {
            throw new SearchUnavailable('moteur arrêté (test)');
        }
    }
}
