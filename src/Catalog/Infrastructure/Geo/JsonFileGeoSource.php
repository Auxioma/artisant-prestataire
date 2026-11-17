<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Geo;

use App\Catalog\Application\Geo\Port\GeoReferentialSource;

/**
 * Fichier local {"regions": […], "departements": […], "communes": […]} au format de l'API (hors ligne, tests, reprise).
 */
final class JsonFileGeoSource implements GeoReferentialSource
{
    /** @var array<mixed>|null */
    private ?array $payload = null;

    public function __construct(private readonly string $path)
    {
    }

    public function regions(): iterable
    {
        return GeoApiPayloadParser::regions($this->payload()['regions'] ?? []);
    }

    public function departments(): iterable
    {
        return GeoApiPayloadParser::departments($this->payload()['departements'] ?? []);
    }

    public function communes(): iterable
    {
        return GeoApiPayloadParser::communes($this->payload()['communes'] ?? []);
    }

    /** @return array<mixed> */
    private function payload(): array
    {
        if (null === $this->payload) {
            $content = is_file($this->path) ? file_get_contents($this->path) : false;
            if (false === $content) {
                throw new \RuntimeException(\sprintf('Fichier de référentiel introuvable : %s', $this->path));
            }
            $decoded = json_decode($content, true, 64, \JSON_THROW_ON_ERROR);
            $this->payload = \is_array($decoded) ? $decoded : [];
        }

        return $this->payload;
    }
}
