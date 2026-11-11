<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Geo;

use App\Catalog\Application\Geo\CommuneRecord;
use App\Catalog\Application\Geo\DepartmentRecord;
use App\Catalog\Application\Geo\RegionRecord;

/** Conversion du format de l'API Découpage administratif (geo.api.gouv.fr) en enregistrements typés. */
final class GeoApiPayloadParser
{
    /** @return iterable<RegionRecord> */
    public static function regions(mixed $payload): iterable
    {
        foreach (self::list($payload) as $item) {
            if (\is_string($item['code'] ?? null) && \is_string($item['nom'] ?? null)) {
                yield new RegionRecord($item['code'], $item['nom']);
            }
        }
    }

    /** @return iterable<DepartmentRecord> */
    public static function departments(mixed $payload): iterable
    {
        foreach (self::list($payload) as $item) {
            if (\is_string($item['code'] ?? null) && \is_string($item['nom'] ?? null) && \is_string($item['codeRegion'] ?? null)) {
                yield new DepartmentRecord($item['code'], $item['nom'], $item['codeRegion']);
            }
        }
    }

    /** @return iterable<CommuneRecord> */
    public static function communes(mixed $payload): iterable
    {
        foreach (self::list($payload) as $item) {
            $coordinates = \is_array($item['centre'] ?? null) && \is_array($item['centre']['coordinates'] ?? null) ? $item['centre']['coordinates'] : [];
            $longitude = $coordinates[0] ?? null;
            $latitude = $coordinates[1] ?? null;
            if (!\is_string($item['code'] ?? null) || !\is_string($item['nom'] ?? null) || !\is_string($item['codeDepartement'] ?? null)
                || !(\is_float($longitude) || \is_int($longitude)) || !(\is_float($latitude) || \is_int($latitude))) {
                continue;
            }
            $postalCodes = \is_array($item['codesPostaux'] ?? null) ? array_values(array_filter($item['codesPostaux'], \is_string(...))) : [];
            $population = $item['population'] ?? null;

            yield new CommuneRecord($item['code'], $item['nom'], $postalCodes, $item['codeDepartement'], \is_int($population) ? $population : null, (float) $latitude, (float) $longitude);
        }
    }

    /** @return list<array<mixed>> */
    private static function list(mixed $payload): array
    {
        return \is_array($payload) ? array_values(array_filter($payload, \is_array(...))) : [];
    }
}
