<?php

declare(strict_types=1);

namespace App\QuoteRequest\Domain\Request;

/** P11.5 — délai souhaité ; « date précise » s'accompagne d'une date. */
enum Timing: string
{
    case Urgent = 'urgent';
    case Week = 'week';
    case Month = 'month';
    case Flexible = 'flexible';
    case Date = 'date';

    public function label(): string
    {
        return match ($this) {
            self::Urgent => 'Urgent (sous 48 h)',
            self::Week => 'Sous 1 semaine',
            self::Month => 'Sous 1 mois',
            self::Flexible => 'Flexible',
            self::Date => 'Date précise',
        };
    }
}
