<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Infrastructure\Projection;

use App\ArtisanProfile\Application\Port\ProfileContent;

/**
 * Implémentation d'attente : le portfolio (PR08.6) arrive avec T-309b ; les photos comptent donc comme « à compléter »
 * dans la jauge, ce qui est exact pour tous les profils.
 */
final readonly class InterimProfileContent implements ProfileContent
{
    public function portfolioPhotos(string $profileId): int
    {
        return 0;
    }
}
