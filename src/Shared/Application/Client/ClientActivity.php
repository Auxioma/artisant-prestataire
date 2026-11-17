<?php

declare(strict_types=1);

namespace App\Shared\Application\Client;

use App\Shared\Application\Bus\Query;

/** C07 « Mon activité sur TrouveMoi » et badges : chiffres d'un client (réponse : ClientActivityCounts). */
final readonly class ClientActivity implements Query
{
    public function __construct(public string $userId)
    {
    }
}
