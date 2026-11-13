<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Pro;

use App\Shared\Application\Bus\Query;

/** PR02 — demandes reçues par l'artisan connecté (aperçu Q15 s'il attend sa vérification) ; null si non artisan. */
final readonly class AvailableRequests implements Query
{
    public function __construct(public string $userId, public ProRequestFilter $filter = new ProRequestFilter(), public int $page = 1, public int $perPage = 20)
    {
    }
}
