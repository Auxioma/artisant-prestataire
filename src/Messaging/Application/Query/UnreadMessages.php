<?php

declare(strict_types=1);

namespace App\Messaging\Application\Query;

use App\Shared\Application\Bus\Query;

/** Nombre de fils non lus (pastilles des menus) ; réponse : int. */
final readonly class UnreadMessages implements Query
{
    public function __construct(public string $userId)
    {
    }
}
