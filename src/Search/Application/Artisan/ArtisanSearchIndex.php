<?php

declare(strict_types=1);

namespace App\Search\Application\Artisan;

use App\ArtisanProfile\Application\Search\SearchableArtisan;
use App\Search\Application\Offer\OfferCard;

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

    /**
     * Sitemap (RG-023) — pages ville activées indexables : par commune (et par métier) le nombre d'artisans couvrant la
     * commune dans le rayon (mêmes règles que la recherche), seuls ceux atteignant le seuil.
     *
     * @return list<array{city: string, job: ?string, count: int}> job null = page ville tous métiers
     */
    public function indexableLocalPages(int $radiusKm, int $minPros): array;

    /** @return array<string, int> code du département => nombre d'artisans publics */
    public function countsByDepartment(): array;

    /** @return list<array{id: string, name: string, commune: ?string, updated_at: string}> artisans publics (fiches P10) */
    public function publicArtisans(): array;

    /** Note moyenne et nombre d'avis publiés (module Review). */
    public function updateRating(string $profileId, ?float $average, int $count): void;

    /**
     * P07 / RG-024 — artisans publics ayant au moins `$minReviews` avis publiés : sur les `$months` derniers mois, ou
     * depuis toujours (null) ; avec l'extrait du dernier avis publié.
     *
     * @param list<string> $jobIds
     *
     * @return list<array{candidate: SearchCandidate, average: float, count: int, last_review: ?string}>
     */
    public function topRated(array $jobIds, ?string $departmentCode, ?int $months, int $minReviews, int $limit): array;

    /** Moyenne des avis publiés sur la période (null : depuis toujours), m par défaut de RG-024. */
    public function ratingMeanSince(?int $months): ?float;

    /**
     * P08 / RG-025 — prochains créneaux « ouverts » des artisans, dans la fenêtre donnée, délai de prévenance de chaque
     * artisan respecté, agendas en pause exclus ; `$partOfDay` : morning (avant 12 h) ou afternoon (à partir de 12 h).
     *
     * @param list<string> $artisanIds
     *
     * @return array<string, list<array{starts_at: \DateTimeImmutable, ends_at: \DateTimeImmutable}>> par artisan, au plus `$perArtisan`
     */
    public function openSlots(array $artisanIds, \DateTimeImmutable $from, \DateTimeImmutable $to, ?string $partOfDay, int $perArtisan): array;

    /**
     * P09 / RG-026 — offres publiées en cours (début ≤ aujourd'hui ≤ fin) d'artisans publics ; `$artisanIds` null :
     * partout ; `$jobIds` : métiers de l'offre, ou de l'artisan si l'offre n'en cible aucun.
     *
     * @param list<string>|null $artisanIds
     * @param list<string>      $jobIds
     *
     * @return list<OfferCard>
     */
    public function activeOffers(?array $artisanIds, array $jobIds, ?string $type, int $limit): array;
}
