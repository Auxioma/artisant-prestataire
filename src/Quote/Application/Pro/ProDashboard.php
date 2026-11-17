<?php

declare(strict_types=1);

namespace App\Quote\Application\Pro;

use App\Shared\Application\Bus\Query;

/** PR01 — tableau de bord de l'artisan connecté, activité sur `$days` jours. Réponse : ProDashboardView|null. */
final readonly class ProDashboard implements Query
{
    public const array PERIODS = ['30' => 30, '365' => 365];

    public function __construct(public string $userId, public int $days = 30)
    {
    }
}
