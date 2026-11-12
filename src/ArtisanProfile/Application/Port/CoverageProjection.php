<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Port;

use App\ArtisanProfile\Domain\Profile\ServiceArea;

/**
 * Projections lues par la diffusion (RG-062) et la recherche (RG-021) : métiers exercés et communes couvertes.
 * Écrites dans la transaction de la commande (RG-042 : effet immédiat), reconstruites à la demande.
 */
interface CoverageProjection
{
    /** @param list<array{job: string, main: bool}> $jobs */
    public function replaceJobs(string $profileId, array $jobs): void;

    /** Recalcule les communes couvertes ; renvoie leur nombre. */
    public function replaceArea(string $profileId, ?ServiceArea $area): int;

    /** Reconstruit les deux projections pour tous les profils (après un import du référentiel géographique). */
    public function rebuildAll(): int;
}
