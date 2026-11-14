<?php

declare(strict_types=1);

namespace App\Billing\Application\Plan;

use App\Shared\Application\Bus\Query;

/** BO08.1 — tous les forfaits (brouillons, publiés, archivés). Réponse : list<PlanView>. */
final readonly class AdminPlans implements Query
{
}
