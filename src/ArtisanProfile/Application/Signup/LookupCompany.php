<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Signup;

use App\Shared\Application\Bus\Query;

/** Réponse : CompanyLookupResult. */
final readonly class LookupCompany implements Query
{
    public function __construct(public string $userId, public string $siret)
    {
    }
}
