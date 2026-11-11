<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Signup;

use App\ArtisanProfile\Domain\Profile\ArtisanProfileRepository;
use App\Shared\Application\Bus\QueryHandler;

final readonly class SignupProgressHandler implements QueryHandler
{
    public function __construct(private ArtisanProfileRepository $profiles)
    {
    }

    public function __invoke(SignupProgress $query): SignupState
    {
        $profile = $this->profiles->findByUserId($query->userId);
        if (null === $profile) {
            return new SignupState(1, null, null, null, null, null, false, [], null);
        }

        return new SignupState(
            $profile->completedStep(),
            $profile->siret()?->value,
            $profile->companyName(),
            $profile->tradeName(),
            $profile->vatNumber(),
            $profile->vatStatus()?->value,
            $profile->isSiretVerified(),
            array_map(static fn ($j): string => $j->value, $profile->jobIds()),
            $profile->mainJobId()?->value,
            $profile->hqPostalCode(),
            $profile->hqCity(),
        );
    }
}
