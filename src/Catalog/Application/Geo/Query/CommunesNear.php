<?php

declare(strict_types=1);

namespace App\Catalog\Application\Geo\Query;

use App\Shared\Application\Bus\Query;

/** Communes actives dans un rayon (PostGIS, ADR-0004) — base des zones d'intervention (RG-021, RG-042). */
final readonly class CommunesNear implements Query
{
    public function __construct(public float $latitude, public float $longitude, public int $radiusKm, public int $limit = 500)
    {
    }
}
