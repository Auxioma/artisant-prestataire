<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidValue;

/**
 * Point WGS84. Pour un artisan, c'est toujours le centroïde de la commune, jamais l'adresse exacte (RG-022).
 */
final readonly class GeoPoint
{
    private const float EARTH_RADIUS_KM = 6371.0088;

    public function __construct(public float $latitude, public float $longitude)
    {
        if ($latitude < -90.0 || $latitude > 90.0) {
            throw InvalidValue::for('GeoPoint', 'latitude hors bornes');
        }
        if ($longitude < -180.0 || $longitude > 180.0) {
            throw InvalidValue::for('GeoPoint', 'longitude hors bornes');
        }
    }

    /** Distance orthodromique (haversine) en kilomètres — la recherche fait foi via PostGIS (ADR-0004). */
    public function distanceKmTo(self $other): float
    {
        $lat1 = deg2rad($this->latitude);
        $lat2 = deg2rad($other->latitude);
        $dLat = $lat2 - $lat1;
        $dLng = deg2rad($other->longitude - $this->longitude);
        $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($a)));
    }

    public function equals(self $other): bool
    {
        return $this->latitude === $other->latitude && $this->longitude === $other->longitude;
    }
}
