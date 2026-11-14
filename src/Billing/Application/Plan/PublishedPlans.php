<?php

declare(strict_types=1);

namespace App\Billing\Application\Plan;

use App\Shared\Application\Bus\Query;

/** P13 — forfaits publiés et publics, dans l'ordre d'affichage. Réponse : list<PlanView>. */
final readonly class PublishedPlans implements Query
{
}
