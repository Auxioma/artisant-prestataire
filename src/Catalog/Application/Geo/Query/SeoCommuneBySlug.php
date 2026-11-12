<?php

declare(strict_types=1);

namespace App\Catalog\Application\Geo\Query;

use App\Shared\Application\Bus\Query;

/** Commune active par son slug (`le-havre-76600`). Réponse : SeoCommune|null. */
final readonly class SeoCommuneBySlug implements Query
{
    public function __construct(public string $slug)
    {
    }
}
