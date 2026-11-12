<?php

declare(strict_types=1);

namespace App\QuoteRequest\Domain\Request;

/** P11.5 — créneaux préférés pour l'intervention. */
enum PreferredSlot: string
{
    case Morning = 'morning';
    case Afternoon = 'afternoon';
    case Evening = 'evening';
    case Weekend = 'weekend';

    public function label(): string
    {
        return match ($this) {
            self::Morning => 'Matin',
            self::Afternoon => 'Après-midi',
            self::Evening => 'En soirée',
            self::Weekend => 'Le week-end',
        };
    }
}
