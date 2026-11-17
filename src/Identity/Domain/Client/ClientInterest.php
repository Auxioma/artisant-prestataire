<?php

declare(strict_types=1);

namespace App\Identity\Domain\Client;

/** C07 « Mes domaines d'intérêt (facultatif) » (Figma 231:12840) : familles de besoins déclarées par le client. */
enum ClientInterest: string
{
    case Renovation = 'renovation';
    case Garden = 'jardin';
    case Automotive = 'automobile';
    case PersonalServices = 'services';
    case Events = 'evenementiel';
    case Other = 'autres';

    public function label(): string
    {
        return match ($this) {
            self::Renovation => 'Rénovation / Habitat',
            self::Garden => 'Jardin & Extérieur',
            self::Automotive => 'Automobile',
            self::PersonalServices => 'Services à la personne',
            self::Events => 'Événementiel',
            self::Other => 'Autres',
        };
    }
}
