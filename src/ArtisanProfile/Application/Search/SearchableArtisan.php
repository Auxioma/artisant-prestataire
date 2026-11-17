<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Search;

/**
 * Données publiques d'un artisan pour la recherche (P02) et les fiches (P10) : jamais de coordonnées ni d'adresse
 * exacte — la position est le centroïde de sa commune (RG-022).
 */
final readonly class SearchableArtisan
{
    /**
     * @param list<string> $jobIds
     * @param list<string> $labels        libellés des qualifications déclarées (filtre « Labels », RG-032 : « vérifié » après contrôle)
     * @param bool         $availableSoon RG-013 : un créneau ouvert dans les `availability.badge_days` jours (prévenance comprise), hors mode pause
     */
    public function __construct(
        public string $profileId,
        public string $userId,
        public string $name,
        public ?string $shortDescription,
        public ?string $logoFileId,
        public ?string $mainJobId,
        public array $jobIds,
        public ?string $communeInsee,
        public ?string $communeName,
        public ?string $departmentCode,
        public ?float $latitude,
        public ?float $longitude,
        public array $labels,
        public int $completion,
        public \DateTimeImmutable $verifiedAt,
        public bool $availableSoon = false,
    ) {
    }
}
