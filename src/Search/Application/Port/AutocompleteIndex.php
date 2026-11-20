<?php

declare(strict_types=1);

namespace App\Search\Application\Port;

/** Index plein texte de l'autocomplétion (RG-010, P01.2) — Meilisearch en production. */
interface AutocompleteIndex
{
    /**
     * Remplace intégralement l'index des métiers (réglages compris) et attend la fin du traitement.
     *
     * @param list<JobDocument> $jobs
     *
     * @throws SearchUnavailable
     */
    public function replaceJobs(array $jobs): void;

    /**
     * @param list<PlaceDocument> $places
     *
     * @throws SearchUnavailable
     */
    public function replacePlaces(array $places): void;

    /**
     * @param list<JobDocument> $jobs
     * @param list<string>      $removedIds
     *
     * @throws SearchUnavailable
     */
    public function updateJobs(array $jobs, array $removedIds): void;

    /**
     * @return list<JobDocument>
     *
     * @throws SearchUnavailable
     */
    public function suggestJobs(string $term, int $limit): array;

    /**
     * @return list<PlaceDocument>
     *
     * @throws SearchUnavailable
     */
    public function suggestPlaces(string $term, int $limit): array;
}
