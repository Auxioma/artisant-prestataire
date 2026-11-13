<?php

declare(strict_types=1);

namespace App\Quote\Domain\Quote;

/** C03.5 — motifs du refus d'un devis par le client (commentaire facultatif en plus). */
enum ClientRefusalReason: string
{
    case Price = 'price';
    case Delay = 'delay';
    case OtherArtisan = 'other_artisan';
    case Abandoned = 'abandoned';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Price => 'Prix',
            self::Delay => 'Délai',
            self::OtherArtisan => 'Autre artisan choisi',
            self::Abandoned => 'Projet abandonné',
            self::Other => 'Autre',
        };
    }
}
