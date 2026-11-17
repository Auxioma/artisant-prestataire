<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Search;

use App\Shared\Application\Bus\Query;

/** Fiche recherchable d'un artisan PUBLIC (vérifié, non masqué — RG-043, RG-130). Réponse : SearchableArtisan|null. */
final readonly class SearchableArtisanOf implements Query
{
    public function __construct(public string $profileId)
    {
    }
}
