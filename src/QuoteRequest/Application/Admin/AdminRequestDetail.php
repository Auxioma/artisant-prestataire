<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Admin;

use App\Shared\Application\Bus\Query;

/** BO04.1 — fiche d'une demande pour la modération. Réponse : ?AdminRequestView. */
final readonly class AdminRequestDetail implements Query
{
    public function __construct(public string $requestId)
    {
    }
}
