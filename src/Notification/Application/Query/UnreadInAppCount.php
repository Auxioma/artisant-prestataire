<?php

declare(strict_types=1);

namespace App\Notification\Application\Query;

use App\Shared\Application\Bus\Query;

/** Compteur de la cloche de l'en-tête. Réponse : int. */
final readonly class UnreadInAppCount implements Query
{
    public function __construct(public string $userId)
    {
    }
}
