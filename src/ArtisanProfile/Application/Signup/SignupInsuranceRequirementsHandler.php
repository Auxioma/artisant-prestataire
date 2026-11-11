<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Signup;

use App\ArtisanProfile\Domain\Profile\ArtisanProfileRepository;
use App\ArtisanProfile\Domain\Profile\InsuranceRequirements;
use App\Shared\Application\Bus\QueryHandler;

final readonly class SignupInsuranceRequirementsHandler implements QueryHandler
{
    public function __construct(private ArtisanProfileRepository $profiles, private SignupRequirements $requirements)
    {
    }

    public function __invoke(SignupInsuranceRequirements $query): InsuranceRequirements
    {
        $profile = $this->profiles->findByUserId($query->userId);

        return null === $profile ? new InsuranceRequirements(false, false, false) : $this->requirements->insurances($profile);
    }
}
