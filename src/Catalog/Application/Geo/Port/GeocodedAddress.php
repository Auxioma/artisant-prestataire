<?php

declare(strict_types=1);

namespace App\Catalog\Application\Geo\Port;

use App\Shared\Domain\ValueObject\GeoPoint;

/** Adresse normalisée par la Base Adresse Nationale. `score` ∈ [0, 1] : à l'appelant de fixer son seuil d'acceptation. */
final readonly class GeocodedAddress
{
    public function __construct(
        public string $label,
        public ?string $houseNumber,
        public ?string $street,
        public string $postalCode,
        public string $inseeCode,
        public string $city,
        public GeoPoint $point,
        public float $score,
        public string $precision,
    ) {
    }
}
