<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Geo;

use App\Catalog\Application\Geo\Query\CommunesNear;
use App\Catalog\Application\Geo\Query\CommuneSummary;
use App\Catalog\Application\Geo\Query\FindCommunes;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/** Requêtes géographiques PostGIS / plein texte paramétrées (aucune concaténation de saisie — cadrage §5.3). */
final readonly class DbalCommuneQueriesHandler implements QueryHandler
{
    private const int MAX_LIMIT = 1000;
    private const int MAX_RADIUS_KM = 500;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<CommuneSummary>
     */
    public function __invoke(CommunesNear|FindCommunes $query): array
    {
        return $query instanceof CommunesNear ? $this->near($query) : $this->find($query);
    }

    /** @return list<CommuneSummary> */
    private function near(CommunesNear $query): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT insee, name, slug, postal_codes, department_code, population,
                    ST_Distance(centroid, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography) / 1000 AS distance_km
             FROM catalog.commune
             WHERE active AND ST_DWithin(centroid, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography, :meters)
             ORDER BY distance_km
             LIMIT :limit',
            [
                'lng' => $query->longitude, 'lat' => $query->latitude,
                'meters' => 1000 * max(0, min($query->radiusKm, self::MAX_RADIUS_KM)),
                'limit' => max(1, min($query->limit, self::MAX_LIMIT)),
            ],
        );

        return array_map($this->summary(...), $rows);
    }

    /** @return list<CommuneSummary> */
    private function find(FindCommunes $query): array
    {
        $term = trim($query->term);
        if (mb_strlen($term) < 2) {
            return [];
        }
        $limit = max(1, min($query->limit, 50));
        if (1 === preg_match('/^\d{2,5}$/', $term)) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT insee, name, slug, postal_codes, department_code, population FROM catalog.commune
                 WHERE active AND EXISTS (SELECT 1 FROM unnest(postal_codes) cp WHERE cp LIKE :prefix)
                 ORDER BY population DESC NULLS LAST, name LIMIT :limit',
                ['prefix' => $term.'%', 'limit' => $limit],
            );
        } else {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT insee, name, slug, postal_codes, department_code, population FROM catalog.commune
                 WHERE active AND (catalog.immutable_unaccent(lower(name)) LIKE catalog.immutable_unaccent(lower(:prefix))
                       OR catalog.immutable_unaccent(lower(name)) % catalog.immutable_unaccent(lower(:term)))
                 ORDER BY (catalog.immutable_unaccent(lower(name)) LIKE catalog.immutable_unaccent(lower(:prefix))) DESC,
                          population DESC NULLS LAST, name
                 LIMIT :limit',
                ['prefix' => str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%', 'term' => $term, 'limit' => $limit],
            );
        }

        return array_map($this->summary(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function summary(array $row): CommuneSummary
    {
        $postalCodes = trim(DbalValue::string($row['postal_codes']), '{}');

        return new CommuneSummary(
            DbalValue::string($row['insee']),
            DbalValue::string($row['name']),
            DbalValue::string($row['slug']),
            '' === $postalCodes ? [] : explode(',', $postalCodes),
            DbalValue::string($row['department_code']),
            null === $row['population'] ? null : DbalValue::int($row['population']),
            isset($row['distance_km']) && is_numeric($row['distance_km']) ? round((float) $row['distance_km'], 1) : null,
        );
    }
}
