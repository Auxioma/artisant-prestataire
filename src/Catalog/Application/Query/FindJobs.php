<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Shared\Application\Bus\Query;

/** Repli de l'autocomplétion métier sans moteur dédié : nom ou synonyme, sans accents, préfixe puis contenu (RG-010). */
final readonly class FindJobs implements Query
{
    public function __construct(public string $term, public int $limit = 8)
    {
    }
}
