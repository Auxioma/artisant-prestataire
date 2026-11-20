<?php

declare(strict_types=1);

namespace App\Shared\Application\Identity;

use App\Shared\Application\Bus\Query;

/**
 * Coordonnées d'envoi d'un compte (adresse et prénom), pour les notifications des modules qui ne possèdent pas
 * l'identité. Traitée par Identity ; réponse : UserContactDetails, ou null si le compte est absent ou anonymisé.
 */
final readonly class UserContact implements Query
{
    public function __construct(public string $userId)
    {
    }
}
