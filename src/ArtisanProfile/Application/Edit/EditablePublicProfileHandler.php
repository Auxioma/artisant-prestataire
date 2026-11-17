<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Edit;

use App\ArtisanProfile\Application\Completion\ProfileCompletionCalculator;
use App\ArtisanProfile\Application\Port\PortfolioStore;
use App\ArtisanProfile\Domain\Profile\ArtisanProfileRepository;
use App\Catalog\Domain\Job\JobId;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;

final readonly class EditablePublicProfileHandler implements QueryHandler
{
    public function __construct(private ArtisanProfileRepository $profiles, private ProfileCompletionCalculator $completion, private SettingsProvider $settings, private PortfolioStore $portfolio)
    {
    }

    public function __invoke(EditablePublicProfile $query): ?EditablePublicProfileView
    {
        $profile = $this->profiles->findByUserId($query->userId);
        if (null === $profile) {
            return null;
        }

        return new EditablePublicProfileView(
            $profile->id()->value,
            $profile->status()->value,
            $profile->isPublic(),
            $profile->companyName() ?? '',
            $profile->tradeName(),
            $profile->hqCity(),
            null !== $profile->logoFileId(),
            $profile->presentation(),
            $profile->services(),
            $profile->openingHours(),
            $profile->isPhonePublic(),
            $this->settings->bool(SettingKey::ProPhonePublicAllowed),
            $this->completion->of($profile),
            $this->portfolio->projects($profile->id()->value, false),
            array_map(static fn (JobId $job): string => $job->value, $profile->jobIds()),
        );
    }
}
