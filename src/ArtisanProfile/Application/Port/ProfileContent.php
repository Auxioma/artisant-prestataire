<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Port;

/** Contenus du profil stockés hors de l'agrégat : photos de réalisations (portfolio PR08.6, T-309b). */
interface ProfileContent
{
    public function portfolioPhotos(string $profileId): int;
}
