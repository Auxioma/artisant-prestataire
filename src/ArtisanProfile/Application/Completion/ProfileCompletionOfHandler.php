<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Completion;

use App\ArtisanProfile\Domain\Profile\ArtisanProfileRepository;
use App\ArtisanProfile\Domain\Profile\ProfileCompletion;
use App\Shared\Application\Bus\QueryHandler;

final readonly class ProfileCompletionOfHandler implements QueryHandler
{
    public function __construct(private ArtisanProfileRepository $profiles, private ProfileCompletionCalculator $calculator)
    {
    }

    public function __invoke(ProfileCompletionOf $query): ?ProfileCompletion
    {
        $profile = $this->profiles->findByUserId($query->userId);

        return null === $profile ? null : $this->calculator->of($profile);
    }
}
