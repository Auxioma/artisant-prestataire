<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Client;

/** C02 « Clôturer » — motif demandé au client (C03.1 « annuler (motif demandé) »). */
enum CloseReason: string
{
    case FoundElsewhere = 'found_elsewhere';
    case Postponed = 'postponed';
    case Abandoned = 'abandoned';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::FoundElsewhere => 'J’ai trouvé un professionnel par ailleurs',
            self::Postponed => 'Projet reporté',
            self::Abandoned => 'Projet abandonné',
            self::Other => 'Autre raison',
        };
    }
}
