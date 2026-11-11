<?php

declare(strict_types=1);

namespace App\Identity\Application\Registration;

use App\Shared\Application\Bus\Query;

/**
 * Le compte d'identifiant donné existe-t-il ? Après une inscription, vrai seulement si ELLE a créé le compte (identifiant
 * UUID v7 fraîchement généré) — jamais pour une adresse déjà inscrite. Réponse : bool.
 */
final readonly class AccountExists implements Query
{
    public function __construct(public string $userId)
    {
    }
}
