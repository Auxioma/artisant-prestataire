<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\PublicProfile;

use App\Shared\Application\Bus\Query;

/** P10 — fiche publique d'un artisan PUBLIC (vérifié, non masqué). Réponse : PublicProfileView|null. */
final readonly class PublicArtisanProfile implements Query
{
    public function __construct(public string $profileId)
    {
    }
}
