<?php

declare(strict_types=1);

namespace App\QuoteRequest\Domain\Request;

/** P11.2 — type de projet. */
enum ProjectType: string
{
    case Repair = 'repair';
    case Installation = 'installation';
    case Renovation = 'renovation';
    case Maintenance = 'maintenance';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Repair => 'Dépannage',
            self::Installation => 'Installation',
            self::Renovation => 'Rénovation',
            self::Maintenance => 'Entretien',
            self::Other => 'Autre',
        };
    }
}
