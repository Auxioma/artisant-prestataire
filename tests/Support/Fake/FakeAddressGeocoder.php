<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Catalog\Application\Geo\Port\AddressGeocoder;
use App\Catalog\Application\Geo\Port\GeocodedAddress;
use App\Shared\Application\Integration\ExternalServiceUnavailable;
use App\Shared\Domain\ValueObject\GeoPoint;

/** Géocodeur de test : quelques adresses fictives du Havre ; « indisponible » simule une panne de la BAN. */
final class FakeAddressGeocoder implements AddressGeocoder
{
    public const string UNAVAILABLE = 'indisponible';
    private const array ADDRESSES = [
        ['12 Rue Fictive 76600 Le Havre', '12', 'Rue Fictive', '76600', '76351', 'Le Havre', 49.4944, 0.1079],
        ['3 Place de l’Essai 76600 Le Havre', '3', 'Place de l’Essai', '76600', '76351', 'Le Havre', 49.4900, 0.1000],
    ];

    public function geocode(string $address, ?string $inseeCode = null, ?string $postalCode = null): ?GeocodedAddress
    {
        foreach ($this->suggest($address, \count(self::ADDRESSES)) as $found) {
            if ((null === $inseeCode || $found->inseeCode === $inseeCode) && (null === $postalCode || $found->postalCode === $postalCode)) {
                return $found;
            }
        }

        return null;
    }

    public function suggest(string $query, int $limit, ?string $inseeCode = null): array
    {
        $needle = mb_strtolower(trim($query));
        if (str_contains($needle, self::UNAVAILABLE)) {
            throw new ExternalServiceUnavailable('geocodage', 'panne simulée');
        }
        $found = [];
        foreach (self::ADDRESSES as [$label, $number, $street, $postal, $insee, $city, $lat, $lon]) {
            $words = preg_split('/[\s,]+/', $needle) ?: [];
            if (null !== $inseeCode && $insee !== $inseeCode) {
                continue;
            }
            if ([] !== $words && [] === array_filter($words, static fn (string $w): bool => '' !== $w && !str_contains(mb_strtolower($label), $w))) {
                $found[] = new GeocodedAddress($label, $number, $street, $postal, $insee, $city, new GeoPoint($lat, $lon), 0.9, 'housenumber');
            }
        }

        return \array_slice($found, 0, $limit);
    }
}
