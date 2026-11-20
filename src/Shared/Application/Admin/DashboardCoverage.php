<?php

declare(strict_types=1);

namespace App\Shared\Application\Admin;

use App\Shared\Application\Bus\Query;

/**
 * BO01.3 — couverture des demandes déposées sur [from, to[ : part sans devis par département, métiers les plus
 * demandés, couples métier × département sans devis (réponse : DashboardCoverageView).
 */
final readonly class DashboardCoverage implements Query
{
    public function __construct(public \DateTimeImmutable $from, public \DateTimeImmutable $to)
    {
    }
}
