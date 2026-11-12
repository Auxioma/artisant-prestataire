<?php

declare(strict_types=1);

namespace App\Search\Application\Artisan;

use App\ArtisanProfile\Application\Search\SearchableArtisan;

/** Projection `search.artisan_search_view` (ADR-0004) : une ligne par artisan public. */
interface ArtisanSearchIndex
{
    public function save(SearchableArtisan $artisan): void;

    public function remove(string $profileId): void;

    /**
     * RG-021 — artisans dont la zone d'intervention contient la commune recherchée ET situés dans le rayon autour de son
     * centroïde (RG-022), exerçant l'un des métiers demandés (tous si vide).
     *
     * @param list<string> $jobIds
     * @param list<string> $labels
     *
     * @return list<SearchCandidate>
     */
    public function candidates(string $communeInsee, int $radiusKm, array $jobIds, array $labels): array;

    /** Moyenne des notes publiées (m par défaut, RG-024) ; null sans avis. */
    public function globalRatingMean(): ?float;
}
