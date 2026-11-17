<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Signup;

use App\Shared\Application\Bus\Command;

/** A03.5 — assurances (décennale, RC Pro) et labels ; dates au format AAAA-MM-JJ. */
final readonly class DeclareInsurances implements Command
{
    /**
     * @param list<array{kind: string, insurer: string, policy: string, valid_until: string}> $insurances
     * @param list<array{name: string, number: string, valid_until: string}>                  $qualifications
     */
    public function __construct(public string $userId, public array $insurances, public array $qualifications)
    {
    }
}
