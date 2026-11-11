<?php

declare(strict_types=1);

namespace App\Catalog\Application\Geo\Query;

use App\Shared\Application\Bus\Query;

/**
 * Recherche de communes par nom (sans accents, préfixe puis similarité) ou code postal (préfixe), triée par population.
 * Repli de l'autocomplétion de lieu quand Meilisearch est indisponible (ADR-0004).
 */
final readonly class FindCommunes implements Query
{
    public function __construct(public string $term, public int $limit = 8)
    {
    }
}
