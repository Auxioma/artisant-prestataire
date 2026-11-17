<?php

declare(strict_types=1);

namespace App\QuoteRequest\Domain\Request;

/** P11.5 — créneaux préférés pour l'intervention (Figma 5:110 : matin, après-midi, soir). */
enum PreferredSlot: string
{
    case Morning = 'morning';
    case Afternoon = 'afternoon';
    case Evening = 'evening';

    public function label(): string
    {
        return match ($this) {
            self::Morning => 'Matin',
            self::Afternoon => 'Après-midi',
            self::Evening => 'Soir',
        };
    }
}
