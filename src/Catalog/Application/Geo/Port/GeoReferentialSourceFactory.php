<?php

declare(strict_types=1);

namespace App\Catalog\Application\Geo\Port;

interface GeoReferentialSourceFactory
{
    /** Source officielle (API Découpage administratif). */
    public function official(): GeoReferentialSource;

    /** Fichier local au format de l'API (hors ligne, tests, reprise). */
    public function file(string $path): GeoReferentialSource;
}
