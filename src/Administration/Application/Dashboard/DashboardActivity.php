<?php

declare(strict_types=1);

namespace App\Administration\Application\Dashboard;

use App\Shared\Application\Bus\Query;

/** BO01.3 — demandes déposées et devis envoyés jour par jour sur [from, to[ (réponse : DashboardActivityView). */
final readonly class DashboardActivity implements Query
{
    public function __construct(public \DateTimeImmutable $from, public \DateTimeImmutable $to)
    {
    }
}
