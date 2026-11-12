<?php

declare(strict_types=1);

namespace App\Catalog\Application\Geo\Query;

use App\Shared\Application\Bus\Query;

/** Géocodage d'une adresse (meilleure correspondance, BAN). Réponse : ?GeocodedAddress ; ExternalServiceUnavailable si la BAN ne répond pas. */
final readonly class GeocodeAddress implements Query
{
    public function __construct(public string $address, public ?string $inseeCode = null, public ?string $postalCode = null)
    {
    }
}
