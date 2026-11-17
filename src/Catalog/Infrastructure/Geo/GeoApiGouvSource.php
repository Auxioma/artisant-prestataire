<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Geo;

use App\Catalog\Application\Geo\Port\GeoReferentialSource;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * API Découpage administratif (Etalab, Licence Ouverte) : COG INSEE, codes postaux La Poste, centroïdes IGN.
 * Client HTTP dédié à l'hôte geo.api.gouv.fr (anti-SSRF, cadrage §5.3), sans URL fournie par l'utilisateur.
 */
final class GeoApiGouvSource implements GeoReferentialSource
{
    private const string COMMUNE_FIELDS = 'nom,code,codesPostaux,centre,population,codeDepartement,codeRegion';

    public function __construct(private readonly HttpClientInterface $geoApiClient)
    {
    }

    public function regions(): iterable
    {
        return GeoApiPayloadParser::regions($this->get('/regions'));
    }

    public function departments(): iterable
    {
        return GeoApiPayloadParser::departments($this->get('/departements'));
    }

    public function communes(): iterable
    {
        return GeoApiPayloadParser::communes($this->get('/communes', ['fields' => self::COMMUNE_FIELDS, 'format' => 'json']));
    }

    /**
     * @param array<string, string> $query
     */
    private function get(string $path, array $query = []): mixed
    {
        return $this->geoApiClient->request('GET', $path, ['query' => $query])->toArray();
    }
}
