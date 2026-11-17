<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Pro;

use App\Shared\Application\Bus\Query;

/** PR03 — détail d'une demande ; null si l'artisan n'y a pas accès (voir `ProRequestAccess`). */
final readonly class ProRequestDetail implements Query
{
    public function __construct(public string $userId, public string $requestId)
    {
    }
}
