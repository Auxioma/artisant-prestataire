<?php

declare(strict_types=1);

namespace App\Quote\Application\Admin;

use App\Shared\Application\Bus\Query;

/** BO04.2 — devis envoyés sur la plateforme (recherche, statut, page). Réponse : AdminQuotesPage. */
final readonly class AdminQuotes implements Query
{
    public const int PER_PAGE = 25;

    public function __construct(public ?string $q = null, public ?string $status = null, public int $page = 1, public int $perPage = self::PER_PAGE)
    {
    }
}
