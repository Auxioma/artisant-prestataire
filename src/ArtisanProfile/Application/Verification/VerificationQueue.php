<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Verification;

use App\Shared\Application\Bus\Query;

/** BO03.2 — dossiers en attente, plus anciens d'abord. Réponse : list<QueueItem>. */
final readonly class VerificationQueue implements Query
{
    public function __construct(public int $limit = 100)
    {
    }
}
