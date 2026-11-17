<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\PublicProfile;

use App\ArtisanProfile\Application\Portfolio\PortfolioProject;
use App\ArtisanProfile\Domain\Profile\OpeningHours;
use App\ArtisanProfile\Domain\Profile\PublicPresentation;
use App\ArtisanProfile\Domain\Profile\ServiceOffer;

/**
 * Données publiques d'une fiche artisan (P10). Jamais l'adresse du siège ni les coordonnées : la commune seulement
 * (RG-022) ; le téléphone n'est jamais dans la page, il s'obtient au clic (RG-030).
 */
final readonly class PublicProfileView
{
    /**
     * @param list<string>           $jobIds
     * @param list<string>           $areaDepartments codes des départements couverts (zone « liste »)
     * @param list<PublicCredential> $insurances
     * @param list<PublicCredential> $qualifications
     * @param list<string>           $verifiedChecks  contrôles réalisés avant l'attribution du badge
     * @param list<ServiceOffer>     $services        prestations et tarifs indicatifs (PR08.5)
     * @param list<PortfolioProject> $portfolio       réalisations dont les photos sont publiables (PR08.6)
     */
    public function __construct(
        public string $profileId,
        public string $name,
        public string $companyName,
        public ?string $tradeName,
        public ?string $description,
        public bool $hasLogo,
        public ?string $mainJobId,
        public array $jobIds,
        public ?string $communeInsee,
        public ?string $communeName,
        public ?string $departmentCode,
        public ?string $postalCode,
        public string $siret,
        public ?string $legalForm,
        public ?string $ape,
        public ?string $vatNumber,
        public ?\DateTimeImmutable $companyCreatedOn,
        public ?string $areaMode,
        public ?string $areaCenterInsee,
        public ?int $areaRadiusKm,
        public array $areaDepartments,
        public int $areaCommunesCount,
        public array $insurances,
        public array $qualifications,
        public array $verifiedChecks,
        public \DateTimeImmutable $verifiedAt,
        public \DateTimeImmutable $memberSince,
        public bool $phoneAvailable,
        public ?PublicPresentation $presentation = null,
        public array $services = [],
        public ?OpeningHours $hours = null,
        public array $portfolio = [],
        public bool $availableSoon = false,
        public bool $acceptsDirectRequests = true,
    ) {
    }
}
