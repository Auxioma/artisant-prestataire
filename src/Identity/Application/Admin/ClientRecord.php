<?php

declare(strict_types=1);

namespace App\Identity\Application\Admin;

use App\Shared\Application\Bus\Query;

/** BO02.2 — fiche d'un client. Réponse : array (infos, acceptations légales, consentement cookies, appareils) ou null. */
final readonly class ClientRecord implements Query
{
    public function __construct(public string $userId)
    {
    }
}
