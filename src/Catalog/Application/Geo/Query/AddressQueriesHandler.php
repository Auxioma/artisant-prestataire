<?php

declare(strict_types=1);

namespace App\Catalog\Application\Geo\Query;

use App\Catalog\Application\Geo\Port\AddressGeocoder;
use App\Catalog\Application\Geo\Port\GeocodedAddress;
use App\Shared\Application\Bus\QueryHandler;

final readonly class AddressQueriesHandler implements QueryHandler
{
    public function __construct(private AddressGeocoder $geocoder)
    {
    }

    /** @return list<GeocodedAddress>|GeocodedAddress|null */
    public function __invoke(SuggestAddresses|GeocodeAddress $query): array|GeocodedAddress|null
    {
        return $query instanceof SuggestAddresses
            ? $this->geocoder->suggest($query->query, $query->limit)
            : $this->geocoder->geocode($query->address, $query->inseeCode, $query->postalCode);
    }
}
