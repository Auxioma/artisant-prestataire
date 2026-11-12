<?php

declare(strict_types=1);

namespace App\Catalog\Application\Geo\Query;

use App\Shared\Application\Bus\Query;

/** P11.5 — suggestions d'adresses (BAN). Réponse : list<GeocodedAddress> ; ExternalServiceUnavailable si la BAN ne répond pas. */
final readonly class SuggestAddresses implements Query
{
    public function __construct(public string $query, public int $limit, public ?string $inseeCode = null)
    {
    }
}
