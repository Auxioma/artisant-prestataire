<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Geo;

use App\Catalog\Application\Geo\Port\AddressGeocoder;
use App\Catalog\Application\Geo\Port\GeocodedAddress;
use App\Shared\Application\Integration\ExternalServiceUnavailable;
use App\Shared\Domain\Exception\InvalidValue;
use App\Shared\Domain\ValueObject\GeoPoint;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service de géocodage de la Géoplateforme (IGN), successeur de api-adresse.data.gouv.fr — même format GeoJSON.
 * L'adresse saisie n'est transmise qu'en paramètre de requête encodé et n'est jamais journalisée (donnée personnelle).
 */
final readonly class BanAddressGeocoder implements AddressGeocoder
{
    private const string SERVICE = 'geocodage';
    private const int MIN_LENGTH = 3;
    private const int MAX_LENGTH = 200; // limite de l'API

    public function __construct(
        #[Target('geocoding.client')]
        private HttpClientInterface $client,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function geocode(string $address, ?string $inseeCode = null, ?string $postalCode = null): ?GeocodedAddress
    {
        $query = trim((string) preg_replace('/\s+/u', ' ', $address));
        if (mb_strlen($query) < self::MIN_LENGTH) {
            return null;
        }
        $parameters = ['q' => mb_substr($query, 0, self::MAX_LENGTH), 'limit' => 1, 'index' => 'address'];
        if (null !== $inseeCode && 1 === preg_match('/^\d[\dAB]\d{3}$/', $inseeCode)) {
            $parameters['citycode'] = $inseeCode;
        }
        if (null !== $postalCode && 1 === preg_match('/^\d{5}$/', $postalCode)) {
            $parameters['postcode'] = $postalCode;
        }

        try {
            $data = $this->client->request('GET', 'search', ['query' => $parameters])->toArray();
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Géocodage injoignable.', ['error' => $e->getMessage()]);
            throw new ExternalServiceUnavailable(self::SERVICE, $e->getMessage(), $e);
        }

        $features = $data['features'] ?? null;
        $feature = \is_array($features) && \is_array($features[0] ?? null) ? $features[0] : null;
        $properties = \is_array($feature['properties'] ?? null) ? $feature['properties'] : null;
        $coordinates = \is_array($feature['geometry'] ?? null) && \is_array($feature['geometry']['coordinates'] ?? null) ? $feature['geometry']['coordinates'] : null;
        if (null === $properties || null === $coordinates || !is_numeric($coordinates[0] ?? null) || !is_numeric($coordinates[1] ?? null)) {
            return null;
        }
        $label = self::text($properties['label'] ?? null);
        $postcode = self::text($properties['postcode'] ?? null);
        $citycode = self::text($properties['citycode'] ?? null);
        $city = self::text($properties['city'] ?? null);
        if (null === $label || null === $postcode || null === $citycode || null === $city) {
            return null;
        }

        try {
            $point = new GeoPoint((float) $coordinates[1], (float) $coordinates[0]);
        } catch (InvalidValue) {
            return null;
        }

        return new GeocodedAddress(
            $label,
            self::text($properties['housenumber'] ?? null),
            self::text($properties['street'] ?? null),
            $postcode,
            $citycode,
            $city,
            $point,
            is_numeric($properties['score'] ?? null) ? (float) $properties['score'] : 0.0,
            self::text($properties['type'] ?? null) ?? 'unknown',
        );
    }

    private static function text(mixed $value): ?string
    {
        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }
}
