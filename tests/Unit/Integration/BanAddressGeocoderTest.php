<?php

declare(strict_types=1);

namespace App\Tests\Unit\Integration;

use App\Catalog\Infrastructure\Geo\BanAddressGeocoder;
use App\Shared\Application\Integration\ExternalServiceUnavailable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Géocodage BAN (Géoplateforme) : paramètres, restriction à la commune, réponse vide, indisponibilité. */
final class BanAddressGeocoderTest extends TestCase
{
    private const string RESPONSE = '{"type":"FeatureCollection","features":[{"type":"Feature","geometry":{"type":"Point","coordinates":[-0.3625,49.1829]},"properties":{"label":"12 Rue de l\'Exemple 14000 Caen","score":0.93,"housenumber":"12","street":"Rue de l\'Exemple","postcode":"14000","citycode":"14118","city":"Caen","type":"housenumber"}}],"query":"12 rue de l exemple"}';

    public function testGeocodesWithinTheGivenCommune(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url): MockResponse {
            self::assertSame('GET', $method);
            $query = [];
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            self::assertStringStartsWith('https://geo.test/search?', $url);
            self::assertSame(['q' => '12 rue de l exemple', 'limit' => '1', 'index' => 'address', 'citycode' => '14118', 'postcode' => '14000'], $query);

            return new MockResponse(self::RESPONSE);
        }, 'https://geo.test/');

        $address = new BanAddressGeocoder($client)->geocode("  12 rue de l   exemple\n", '14118', '14000');

        self::assertNotNull($address);
        self::assertSame("12 Rue de l'Exemple 14000 Caen", $address->label);
        self::assertSame(['12', "Rue de l'Exemple", '14000', '14118', 'Caen', 'housenumber'], [$address->houseNumber, $address->street, $address->postalCode, $address->inseeCode, $address->city, $address->precision]);
        self::assertEqualsWithDelta(49.1829, $address->point->latitude, 1e-6);
        self::assertEqualsWithDelta(-0.3625, $address->point->longitude, 1e-6);
        self::assertEqualsWithDelta(0.93, $address->score, 1e-9);
    }

    public function testInvalidFiltersAreIgnoredAndTooShortInputIsNotSent(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url): MockResponse {
            self::assertStringNotContainsString('citycode', $url);
            self::assertStringNotContainsString('postcode', $url);

            return new MockResponse('{"type":"FeatureCollection","features":[]}');
        }, 'https://geo.test/');
        $geocoder = new BanAddressGeocoder($client);

        self::assertNull($geocoder->geocode('ab'));
        self::assertSame(0, $client->getRequestsCount());
        self::assertNull($geocoder->geocode('rue inconnue', "14118' OR 1=1", 'abcde'));
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testMalformedFeatureIsIgnored(): void
    {
        $client = new MockHttpClient(new MockResponse('{"features":[{"geometry":{"coordinates":[500,500]},"properties":{"label":"x","postcode":"14000","citycode":"14118","city":"Caen"}}]}'));

        self::assertNull(new BanAddressGeocoder($client)->geocode('12 rue de l exemple'));
    }

    public function testServerErrorIsReportedAsUnavailable(): void
    {
        $this->expectException(ExternalServiceUnavailable::class);
        new BanAddressGeocoder(new MockHttpClient(new MockResponse('', ['http_code' => 502])))->geocode('12 rue de l exemple');
    }
}
