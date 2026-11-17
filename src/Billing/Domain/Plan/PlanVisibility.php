<?php

declare(strict_types=1);

namespace App\Billing\Domain\Plan;

/** BO08.1 — forfait public (page Tarifs) ou privé (accessible par lien / code, ex. partenariat). */
enum PlanVisibility: string
{
    case Public = 'public';
    case Private = 'private';

    public function label(): string
    {
        return match ($this) {
            self::Public => 'Public',
            self::Private => 'Privé (lien / code)',
        };
    }
}
