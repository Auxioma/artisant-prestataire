<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Geo;

use App\Catalog\Application\Geo\Query\AreaCoverage;
use App\Catalog\Application\Geo\Query\CommuneIndexEntries;
use App\Catalog\Application\Geo\Query\CommunesByInsee;
use App\Catalog\Application\Geo\Query\CommunesNear;
use App\Catalog\Application\Geo\Query\CommuneSummary;
use App\Catalog\Application\Geo\Query\Coverage;
use App\Catalog\Application\Geo\Query\Departments;
use App\Catalog\Application\Geo\Query\FindCommunes;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/** Requêtes géographiques PostGIS / plein texte paramétrées (aucune concaténation de saisie — cadrage §5.3). */
final readonly class DbalCommuneQueriesHandler implements QueryHandler
{
    private const int MAX_LIMIT = 1000;
    private const int MAX_RADIUS_KM = 500;
    private const string INSEE = '/^(\d{2}|2A|2B)\d{3}$/';
    private const string DEPARTMENT = '/^(\d{2}|2A|2B|97\d)$/';
    private const string FIND_SELECT = 'SELECT c.insee, c.name, c.slug, c.postal_codes, c.department_code, c.population, d.name AS department_name
        FROM catalog.commune c JOIN catalog.department d ON d.code = c.department_code';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<CommuneSummary>|array<string, string>|Coverage
     */
    public function __invoke(CommunesNear|FindCommunes|CommuneIndexEntries|CommunesByInsee|Departments|AreaCoverage $query): array|Coverage
    {
        return match (true) {
            $query instanceof CommunesNear => $this->near($query),
            $query instanceof FindCommunes => $this->find($query),
            $query instanceof CommunesByInsee => $this->byInsee($query),
            $query instanceof Departments => $this->departments(),
            $query instanceof AreaCoverage => $this->coverage($query),
            default => $this->indexEntries(),
        };
    }

    /** @return list<CommuneSummary> */
    private function byInsee(CommunesByInsee $query): array
    {
        $codes = array_values(array_unique(array_filter($query->insee, static fn (string $c): bool => 1 === preg_match(self::INSEE, $c))));
        if ([] === $codes) {
            return [];
        }

        return array_map($this->summary(...), $this->connection->fetchAllAssociative(
            self::FIND_SELECT.' WHERE c.active AND c.insee IN (:codes) ORDER BY c.population DESC NULLS LAST, c.name LIMIT :limit',
            ['codes' => \array_slice($codes, 0, self::MAX_LIMIT), 'limit' => self::MAX_LIMIT],
            ['codes' => ArrayParameterType::STRING],
        ));
    }

    /** @return array<string, string> */
    private function departments(): array
    {
        $departments = [];
        foreach ($this->connection->fetchAllAssociative('SELECT code, name FROM catalog.department ORDER BY code') as $row) {
            $departments[DbalValue::string($row['code'])] = DbalValue::string($row['name']);
        }

        return $departments;
    }

    private function coverage(AreaCoverage $query): Coverage
    {
        if (null !== $query->centerInsee && null !== $query->radiusKm) {
            $row = $this->connection->fetchAssociative(
                'SELECT count(*) AS n, COALESCE(sum(c.population), 0) AS p
                 FROM catalog.commune o JOIN catalog.commune c ON c.active AND ST_DWithin(c.centroid, o.centroid, :meters)
                 WHERE o.insee = :center AND o.active',
                ['center' => $query->centerInsee, 'meters' => 1000 * max(0, min($query->radiusKm, self::MAX_RADIUS_KM))],
            );
        } else {
            $departments = array_values(array_filter($query->departments, static fn (string $d): bool => 1 === preg_match(self::DEPARTMENT, $d)));
            $communes = array_values(array_filter($query->communes, static fn (string $c): bool => 1 === preg_match(self::INSEE, $c)));
            if ([] === $departments && [] === $communes) {
                return new Coverage(0, 0);
            }
            // Tableaux vides remplacés par une valeur impossible : IN () n'est pas du SQL valide.
            $row = $this->connection->fetchAssociative(
                'SELECT count(*) AS n, COALESCE(sum(population), 0) AS p FROM catalog.commune
                 WHERE active AND (department_code IN (:departments) OR insee IN (:communes))',
                ['departments' => [] === $departments ? [''] : $departments, 'communes' => [] === $communes ? [''] : $communes],
                ['departments' => ArrayParameterType::STRING, 'communes' => ArrayParameterType::STRING],
            );
        }

        return false === $row ? new Coverage(0, 0) : new Coverage(DbalValue::int($row['n']), DbalValue::int($row['p']));
    }

    /** @return list<CommuneSummary> */
    private function indexEntries(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.insee, c.name, c.slug, c.postal_codes, c.department_code, c.population, d.name AS department_name
             FROM catalog.commune c JOIN catalog.department d ON d.code = c.department_code WHERE c.active ORDER BY c.insee',
        );

        return array_map($this->summary(...), $rows);
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
                self::FIND_SELECT.' WHERE c.active AND EXISTS (SELECT 1 FROM unnest(c.postal_codes) cp WHERE cp LIKE :prefix)
                 ORDER BY c.population DESC NULLS LAST, c.name LIMIT :limit',
                ['prefix' => $term.'%', 'limit' => $limit],
            );
        } else {
            $rows = $this->connection->fetchAllAssociative(
                self::FIND_SELECT.' WHERE c.active AND (catalog.immutable_unaccent(lower(c.name)) LIKE catalog.immutable_unaccent(lower(:prefix))
                       OR catalog.immutable_unaccent(lower(c.name)) % catalog.immutable_unaccent(lower(:term)))
                 ORDER BY (catalog.immutable_unaccent(lower(c.name)) LIKE catalog.immutable_unaccent(lower(:prefix))) DESC,
                          c.population DESC NULLS LAST, c.name
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
            isset($row['department_name']) ? DbalValue::string($row['department_name']) : null,
        );
    }
}
