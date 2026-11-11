<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Geo;

use App\Catalog\Application\Geo\Port\GeoReferentialSource;
use App\Catalog\Application\Geo\Port\GeoReferentialSourceFactory;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class GeoSourceFactory implements GeoReferentialSourceFactory
{
    public function __construct(#[Target('geo_api.client')] private HttpClientInterface $geoApiClient)
    {
    }

    public function official(): GeoReferentialSource
    {
        return new GeoApiGouvSource($this->geoApiClient);
    }

    public function file(string $path): GeoReferentialSource
    {
        return new JsonFileGeoSource($path);
    }
}
