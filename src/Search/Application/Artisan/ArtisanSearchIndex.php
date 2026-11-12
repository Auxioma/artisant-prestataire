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

    /**
     * Nombre d'artisans publics par métier, en France ou dans un département.
     *
     * @return array<string, int> id du métier => nombre d'artisans
     */
    public function countsByJob(?string $departmentCode = null): array;

    /**
     * Nombre d'artisans publics distincts exerçant l'un des métiers (tous si vide), en France ou dans un département.
     *
     * @param list<string> $jobIds
     */
    public function countAmong(array $jobIds, ?string $departmentCode = null): int;

    /**
     * RG-011 — artisans éligibles aux blocs de mise en avant (complétude minimale), exerçant l'un des métiers (tous si
     * vide), les mieux notés d'abord puis les plus complets ; distance nulle (aucun lieu connu).
     *
     * @param list<string> $jobIds
     *
     * @return list<SearchCandidate>
     */
    public function featured(array $jobIds, ?string $departmentCode, int $minCompletion, int $limit): array;

    /**
     * P06 — annuaire alphabétique des artisans publics, filtré par métiers, département, initiale (`#` = chiffre),
     * labels et note minimale.
     *
     * @param list<string> $jobIds
     * @param list<string> $labels
     *
     * @return array{total: int, candidates: list<SearchCandidate>}
     */
    public function directory(array $jobIds, ?string $departmentCode, ?string $letter, array $labels, ?float $minRating, int $offset, int $limit): array;

    /**
     * Initiales présentes dans l'annuaire (filtre alphabétique sans lettre vide).
     *
     * @return list<string>
     */
    public function directoryLetters(): array;
}
