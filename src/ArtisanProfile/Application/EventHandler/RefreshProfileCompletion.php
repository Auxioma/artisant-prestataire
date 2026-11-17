<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\EventHandler;

use App\ArtisanProfile\Application\Completion\ProfileCompletionCalculator;
use App\ArtisanProfile\Domain\Profile\ArtisanProfileId;
use App\ArtisanProfile\Domain\Profile\ArtisanProfileRepository;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanInsurancesDeclared;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanLogoChanged;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanPublicProfileUpdated;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanServiceAreaDefined;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanSignupSubmitted;
use App\Shared\Application\Bus\EventHandler;

/** Taux de complétude conservé à jour (classement RG-020, mises en avant RG-011, BO03) après chaque changement du profil. */
final readonly class RefreshProfileCompletion implements EventHandler
{
    public function __construct(private ArtisanProfileRepository $profiles, private ProfileCompletionCalculator $calculator)
    {
    }

    public function __invoke(ArtisanServiceAreaDefined|ArtisanInsurancesDeclared|ArtisanSignupSubmitted|ArtisanLogoChanged|ArtisanPublicProfileUpdated $event): void
    {
        $profile = $this->profiles->find(new ArtisanProfileId($event->profileId));
        if (null !== $profile) {
            $profile->recordCompletion($this->calculator->of($profile));
        }
    }
}
