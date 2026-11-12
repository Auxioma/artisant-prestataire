<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Infrastructure\Projection;

use App\ArtisanProfile\Application\Port\ProfileContent;

/**
 * Implémentation d'attente : portfolio et prestations (T-309) et horaires (T-702) ne sont pas encore livrés ;
 * ces éléments comptent donc comme « à compléter » dans la jauge, ce qui est exact pour tous les profils.
 */
final readonly class InterimProfileContent implements ProfileContent
{
    public function portfolioPhotos(string $profileId): int
    {
        return 0;
    }

    public function services(string $profileId): int
    {
        return 0;
    }

    public function hasOpeningHours(string $profileId): bool
    {
        return false;
    }
}
