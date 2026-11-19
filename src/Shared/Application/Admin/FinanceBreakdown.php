<?php

declare(strict_types=1);

namespace App\Shared\Application\Admin;

use App\Shared\Application\Bus\Query;

/**
 * BO15.3 — détails financiers de la période [from, to[ (réponse : FinanceBreakdownView) : MRR par forfait à la fin de la
 * période, cohortes de rétention des abonnés, artisans en impayé, codes promo utilisés, dernier rapprochement Stripe.
 */
final readonly class FinanceBreakdown implements Query
{
    public function __construct(public \DateTimeImmutable $from, public \DateTimeImmutable $to, public \DateTimeImmutable $now)
    {
    }
}
