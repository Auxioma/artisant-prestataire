<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\PublicProfile;

use App\Shared\Application\Bus\Query;

/**
 * RG-030 — téléphone d'un artisan public qui a choisi de l'afficher (et si la plateforme l'autorise). Réponse : string
 * (format national lisible) ou null.
 */
final readonly class ArtisanPhone implements Query
{
    public function __construct(public string $profileId)
    {
    }
}
