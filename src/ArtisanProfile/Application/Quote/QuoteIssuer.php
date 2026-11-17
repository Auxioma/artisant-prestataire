<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Quote;

use App\Shared\Application\Bus\Query;

/** PR04.1 / RG-120 — entreprise émettrice d'un devis (compte artisan connecté) ; null si aucun profil. */
final readonly class QuoteIssuer implements Query
{
    public function __construct(public string $userId)
    {
    }
}
