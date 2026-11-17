<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure;

use App\Catalog\Application\Port\JobUsageCounter;
use App\Catalog\Domain\Job\JobId;

/**
 * Aucun module ne référence encore les métiers (profils artisans : T-208, demandes : T-401).
 * À remplacer par une implémentation interrogeant ces modules dès leur livraison.
 */
final readonly class InterimJobUsageCounter implements JobUsageCounter
{
    public function count(JobId $job): int
    {
        return 0;
    }
}
