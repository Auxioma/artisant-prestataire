<?php

declare(strict_types=1);

namespace App\Administration\Application\Dashboard;

use App\Shared\Application\Bus\Query;

/**
 * BO01.1 — indicateurs d'un mois (« 2026-10 », mois en cours si null) comparés au même mois un an plus tôt
 * (réponse : DashboardKpisView). Le mois en cours s'arrête à $now, et la période N-1 aussi, à la même date.
 */
final readonly class DashboardKpis implements Query
{
    public function __construct(public ?string $month, public \DateTimeImmutable $now)
    {
    }
}
