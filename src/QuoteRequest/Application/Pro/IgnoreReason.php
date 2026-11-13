<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Pro;

/** PR02 « Ignorer » — motif facultatif, pour améliorer le ciblage des diffusions. */
enum IgnoreReason: string
{
    case NotMyJob = 'not_my_job';
    case TooFar = 'too_far';
    case Budget = 'budget';
    case NoTime = 'no_time';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::NotMyJob => 'Hors de mon métier',
            self::TooFar => 'Trop loin',
            self::Budget => 'Budget inadapté',
            self::NoTime => 'Pas disponible',
            self::Other => 'Autre raison',
        };
    }
}
