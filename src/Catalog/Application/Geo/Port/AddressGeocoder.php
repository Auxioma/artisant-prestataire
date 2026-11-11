<?php

declare(strict_types=1);

namespace App\Catalog\Application\Geo\Port;

use App\Shared\Application\Integration\ExternalServiceUnavailable;

/**
 * Géocodage d'adresse (BAN, ADR-0004) : adresse d'intervention d'une demande, adresse de l'entreprise. Le centroïde
 * de commune (PostGIS) reste le repli quand l'adresse n'est pas trouvée (RG-022).
 */
interface AddressGeocoder
{
    /**
     * @param string|null $inseeCode  restreint la recherche à une commune (fortement recommandé)
     * @param string|null $postalCode restreint la recherche à un code postal
     *
     * @return GeocodedAddress|null meilleure correspondance, null si aucune
     *
     * @throws ExternalServiceUnavailable
     */
    public function geocode(string $address, ?string $inseeCode = null, ?string $postalCode = null): ?GeocodedAddress;
}
