<?php

declare(strict_types=1);

namespace App\Quote\Application\Pro;

use App\Shared\Application\Bus\Query;

/** PR05 — devis de l'artisan connecté (onglets Devis / Chantiers, recherche, filtres, page). Réponse : ProQuotesPage|null. */
final readonly class ProQuotes implements Query
{
    public function __construct(public string $userId, public ProQuoteFilter $filter, public int $page = 1, public ?int $perPage = null)
    {
    }
}
