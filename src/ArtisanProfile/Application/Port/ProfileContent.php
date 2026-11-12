<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Port;

/** Contenus du profil édités dans l'espace pro (PR06, PR08) : photos de réalisations, prestations, horaires. */
interface ProfileContent
{
    public function portfolioPhotos(string $profileId): int;

    public function services(string $profileId): int;

    public function hasOpeningHours(string $profileId): bool;
}
