<?php

declare(strict_types=1);

namespace App\Identity\Domain\Client;

/** A02 : particulier, ou professionnel client (entreprise qui commande des travaux). */
enum ClientType: string
{
    case Individual = 'particulier';
    case Business = 'professionnel';
}
