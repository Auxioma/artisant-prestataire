<?php

declare(strict_types=1);

namespace App\Billing\Domain\Plan;

/** Périodicité d'un prix : mensuel sans engagement, annuel (Q5). */
enum BillingInterval: string
{
    case Month = 'month';
    case Year = 'year';

    public function label(): string
    {
        return match ($this) {
            self::Month => 'Prix HT mensuel',
            self::Year => 'Prix HT annuel',
        };
    }

    /** Nom du champ de saisie (BO08). */
    public function field(): string
    {
        return match ($this) {
            self::Month => 'monthly_price',
            self::Year => 'yearly_price',
        };
    }
}
