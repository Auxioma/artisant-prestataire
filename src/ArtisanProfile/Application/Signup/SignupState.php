<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Signup;

final readonly class SignupState
{
    /** @param list<string> $jobIds */
    public function __construct(
        public int $completedStep,
        public ?string $siret,
        public ?string $companyName,
        public ?string $tradeName,
        public ?string $vatNumber,
        public ?string $vatStatus,
        public bool $siretVerified,
        public array $jobIds,
        public ?string $mainJobId,
        public ?string $hqPostalCode = null,
        public ?string $hqCity = null,
    ) {
    }
}
