<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Signup;

use App\Shared\Application\Bus\Query;

/** A03.5 — assurances exigées par les métiers de l'artisan. Réponse : InsuranceRequirements. */
final readonly class SignupInsuranceRequirements implements Query
{
    public function __construct(public string $userId)
    {
    }
}
