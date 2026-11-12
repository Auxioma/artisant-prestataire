<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Completion;

use App\ArtisanProfile\Application\Port\ProfileContent;
use App\ArtisanProfile\Domain\Profile\ArtisanProfile;
use App\ArtisanProfile\Domain\Profile\ProfileCompletion;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;

/** PR08.1 — complétude d'un profil, pondérations et objectif de photos lus dans les paramètres (RG-014). */
final readonly class ProfileCompletionCalculator
{
    public function __construct(private ProfileContent $content, private SettingsProvider $settings)
    {
    }

    public function of(ArtisanProfile $profile): ProfileCompletion
    {
        $id = $profile->id()->value;

        return ProfileCompletion::compute(
            $this->settings->weights(SettingKey::ProCompletionWeights),
            logo: null !== $profile->logoFileId(),
            description: null !== $profile->shortDescription(),
            photos: $this->content->portfolioPhotos($id),
            photosTarget: $this->settings->int(SettingKey::ProCompletionPhotosTarget),
            hours: $this->content->hasOpeningHours($id),
            zone: null !== $profile->serviceArea(),
            services: $this->content->services($id),
            labels: [] !== $profile->qualifications(),
        );
    }
}
