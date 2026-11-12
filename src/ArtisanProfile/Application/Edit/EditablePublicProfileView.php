<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Edit;

use App\ArtisanProfile\Domain\Profile\OpeningHours;
use App\ArtisanProfile\Domain\Profile\ProfileCompletion;
use App\ArtisanProfile\Domain\Profile\PublicPresentation;
use App\ArtisanProfile\Domain\Profile\ServiceOffer;

final readonly class EditablePublicProfileView
{
    /** @param list<ServiceOffer> $services */
    public function __construct(
        public string $profileId,
        public string $status,
        public bool $public,
        public string $companyName,
        public ?string $tradeName,
        public ?string $communeName,
        public bool $hasLogo,
        public PublicPresentation $presentation,
        public array $services,
        public OpeningHours $hours,
        public bool $phonePublic,
        public bool $phoneAllowed,
        public ProfileCompletion $completion,
    ) {
    }
}
