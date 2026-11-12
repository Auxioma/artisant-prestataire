<?php

declare(strict_types=1);

namespace App\QuoteRequest\Domain\Request;

/** P11.5 — statut du demandeur vis-à-vis du lieu d'intervention. */
enum OccupantStatus: string
{
    case Owner = 'owner';
    case Tenant = 'tenant';
    case Syndic = 'syndic';
    case Company = 'company';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Propriétaire',
            self::Tenant => 'Locataire',
            self::Syndic => 'Syndic',
            self::Company => 'Entreprise',
        };
    }
}
