<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\Job\JobId;

/** Nombre de références à un métier dans les autres modules (profils artisans, demandes) — BO06. */
interface JobUsageCounter
{
    public function count(JobId $job): int;
}
