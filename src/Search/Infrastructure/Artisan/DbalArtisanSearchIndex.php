<?php

declare(strict_types=1);

namespace App\Search\Infrastructure\Artisan;

use App\ArtisanProfile\Application\Search\SearchableArtisan;
use App\Search\Application\Artisan\ArtisanSearchIndex;
use App\Search\Application\Artisan\SearchCandidate;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/** Projection PostGIS (requêtes paramétrées, cadrage §5.3) ; la zone d'intervention vient de `artisan.service_area_commune`. */
final readonly class DbalArtisanSearchIndex implements ArtisanSearchIndex
{
    private const int MAX_RADIUS_KM = 500;
    private const int MAX_CANDIDATES = 2000;

    public function __construct(private Connection $connection)
    {
    }

    public function save(SearchableArtisan $a): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO search.artisan_search_view (artisan_id, user_id, name, short_description, logo_file_id, main_job_id, job_ids, commune_insee, commune_name,
                    department_code, location, labels, completion, verified_at, available_soon, updated_at)
                VALUES (:id, :user, :name, :description, :logo, :main, CAST(:jobs AS uuid[]), :insee, :commune, :department,
                    CASE WHEN CAST(:lat AS double precision) IS NULL THEN NULL ELSE ST_SetSRID(ST_MakePoint(CAST(:lng AS double precision), CAST(:lat AS double precision)), 4326)::geography END,
                    CAST(:labels AS text[]), :completion, :verified, :available, now())
                ON CONFLICT (artisan_id) DO UPDATE SET user_id = EXCLUDED.user_id, name = EXCLUDED.name, short_description = EXCLUDED.short_description,
                    logo_file_id = EXCLUDED.logo_file_id, main_job_id = EXCLUDED.main_job_id, job_ids = EXCLUDED.job_ids, commune_insee = EXCLUDED.commune_insee,
                    commune_name = EXCLUDED.commune_name, department_code = EXCLUDED.department_code, location = EXCLUDED.location, labels = EXCLUDED.labels,
                    completion = EXCLUDED.completion, verified_at = EXCLUDED.verified_at, available_soon = EXCLUDED.available_soon, updated_at = now()
                SQL,
            [
                'id' => $a->profileId, 'user' => $a->userId, 'name' => $a->name, 'description' => $a->shortDescription, 'logo' => $a->logoFileId,
                'main' => $a->mainJobId, 'jobs' => self::pgArray($a->jobIds), 'insee' => $a->communeInsee, 'commune' => $a->communeName,
                'department' => $a->departmentCode, 'lat' => $a->latitude, 'lng' => $a->longitude, 'labels' => self::pgArray($a->labels),
                'completion' => $a->completion, 'verified' => $a->verifiedAt->format(\DATE_ATOM), 'available' => $a->availableSoon ? 'true' : 'false',
            ],
        );
    }

    public function remove(string $profileId): void
    {
        $this->connection->executeStatement('DELETE FROM search.artisan_search_view WHERE artisan_id = :id', ['id' => $profileId]);
    }

    public function candidates(string $communeInsee, int $radiusKm, array $jobIds, array $labels): array
    {
        $jobIds = self::uuids($jobIds);
        if (1 !== preg_match('/^(\d{2}|2A|2B)\d{3}$/', $communeInsee)) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT v.artisan_id, v.name, v.short_description, v.main_job_id, v.commune_name, v.department_code, v.labels, v.completion,
                       v.rating_avg, v.reviews_count, v.response_rate, v.available_soon, v.logo_file_id IS NOT NULL AS has_logo, v.job_ids,
                       ST_Distance(v.location, o.centroid) / 1000 AS distance_km
                FROM catalog.commune o
                JOIN search.artisan_search_view v ON v.location IS NOT NULL AND ST_DWithin(v.location, o.centroid, :meters)
                JOIN artisan.service_area_commune z ON z.artisan_id = v.artisan_id AND z.insee = o.insee
                WHERE o.insee = :insee
                  AND (cardinality(CAST(:jobs AS uuid[])) = 0 OR v.job_ids && CAST(:jobs AS uuid[]))
                  AND (cardinality(CAST(:labels AS text[])) = 0 OR v.labels && CAST(:labels AS text[]))
                LIMIT :max
                SQL,
            [
                'insee' => $communeInsee, 'meters' => 1000 * max(1, min($radiusKm, self::MAX_RADIUS_KM)), 'jobs' => self::pgArray($jobIds),
                'labels' => self::pgArray($labels), 'max' => self::MAX_CANDIDATES,
            ],
        );

        return array_map(static fn (array $r): SearchCandidate => self::candidate($r, $jobIds), $rows);
    }

    public function globalRatingMean(): ?float
    {
        $mean = $this->connection->fetchOne('SELECT sum(rating_avg * reviews_count) / nullif(sum(reviews_count), 0) FROM search.artisan_search_view WHERE reviews_count > 0');

        return is_numeric($mean) ? (float) $mean : null;
    }

    public function countsByJob(?string $departmentCode = null): array
    {
        $counts = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT j AS job_id, count(*) AS n FROM search.artisan_search_view v, unnest(v.job_ids) j
             WHERE CAST(:department AS text) IS NULL OR v.department_code = :department GROUP BY j',
            ['department' => $departmentCode],
        ) as $row) {
            $counts[DbalValue::string($row['job_id'])] = DbalValue::int($row['n']);
        }

        return $counts;
    }

    public function countAmong(array $jobIds, ?string $departmentCode = null): int
    {
        return DbalValue::int($this->connection->fetchOne(
            'SELECT count(*) FROM search.artisan_search_view v
             WHERE (cardinality(CAST(:jobs AS uuid[])) = 0 OR v.job_ids && CAST(:jobs AS uuid[]))
               AND (CAST(:department AS text) IS NULL OR v.department_code = :department)',
            ['jobs' => self::pgArray(self::uuids($jobIds)), 'department' => $departmentCode],
        ));
    }

    public function featured(array $jobIds, ?string $departmentCode, int $minCompletion, int $limit): array
    {
        $jobIds = self::uuids($jobIds);
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT v.artisan_id, v.name, v.short_description, v.main_job_id, v.commune_name, v.department_code, v.labels, v.completion,
                       v.rating_avg, v.reviews_count, v.response_rate, v.available_soon, v.logo_file_id IS NOT NULL AS has_logo, v.job_ids, 0 AS distance_km
                FROM search.artisan_search_view v
                WHERE v.completion >= :min
                  AND (cardinality(CAST(:jobs AS uuid[])) = 0 OR v.job_ids && CAST(:jobs AS uuid[]))
                  AND (CAST(:department AS text) IS NULL OR v.department_code = :department)
                ORDER BY v.rating_avg DESC NULLS LAST, v.reviews_count DESC, v.completion DESC, v.verified_at, v.artisan_id
                LIMIT :limit
                SQL,
            ['min' => $minCompletion, 'jobs' => self::pgArray($jobIds), 'department' => $departmentCode, 'limit' => max(1, min($limit, self::MAX_CANDIDATES))],
        );

        return array_map(static fn (array $r): SearchCandidate => self::candidate($r, $jobIds), $rows);
    }

    public function directory(array $jobIds, ?string $departmentCode, ?string $letter, array $labels, ?float $minRating, int $offset, int $limit): array
    {
        $where = <<<'SQL'
            (cardinality(CAST(:jobs AS uuid[])) = 0 OR v.job_ids && CAST(:jobs AS uuid[]))
            AND (CAST(:department AS text) IS NULL OR v.department_code = :department)
            AND (CAST(:letter AS text) IS NULL OR
                 CASE WHEN :letter = '#' THEN upper(left(catalog.immutable_unaccent(v.name), 1)) !~ '^[A-Z]$'
                      ELSE upper(left(catalog.immutable_unaccent(v.name), 1)) = :letter END)
            AND (cardinality(CAST(:labels AS text[])) = 0 OR v.labels && CAST(:labels AS text[]))
            AND (CAST(:rating AS numeric) IS NULL OR v.rating_avg >= CAST(:rating AS numeric))
            SQL;
        $jobIds = self::uuids($jobIds);
        $params = ['jobs' => self::pgArray($jobIds), 'department' => $departmentCode, 'letter' => $letter, 'labels' => self::pgArray($labels), 'rating' => $minRating];
        $total = DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM search.artisan_search_view v WHERE '.$where, $params));
        $rows = $this->connection->fetchAllAssociative(
            'SELECT v.artisan_id, v.name, v.short_description, v.main_job_id, v.commune_name, v.department_code, v.labels, v.completion,
                    v.rating_avg, v.reviews_count, v.response_rate, v.available_soon, v.logo_file_id IS NOT NULL AS has_logo, v.job_ids, 0 AS distance_km
             FROM search.artisan_search_view v WHERE '.$where.'
             ORDER BY catalog.immutable_unaccent(lower(v.name)), v.artisan_id OFFSET :offset LIMIT :limit',
            $params + ['offset' => max(0, $offset), 'limit' => max(1, min($limit, self::MAX_CANDIDATES))],
        );

        return ['total' => $total, 'candidates' => array_map(static fn (array $r): SearchCandidate => self::candidate($r, $jobIds), $rows)];
    }

    public function indexableLocalPages(int $radiusKm, int $minPros): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                WITH coverage AS (
                    SELECT c.slug AS city, v.artisan_id, v.job_ids
                    FROM catalog.commune c
                    JOIN artisan.service_area_commune z ON z.insee = c.insee
                    JOIN search.artisan_search_view v ON v.artisan_id = z.artisan_id AND v.location IS NOT NULL AND ST_DWithin(v.location, c.centroid, :meters)
                    WHERE c.active AND c.seo_page_enabled
                )
                SELECT city, NULL AS job, count(DISTINCT artisan_id) AS n FROM coverage GROUP BY city HAVING count(DISTINCT artisan_id) >= :min
                UNION ALL
                SELECT cv.city, j.slug AS job, count(DISTINCT cv.artisan_id) AS n
                FROM coverage cv
                JOIN catalog.job j ON j.id = ANY (cv.job_ids) AND j.active
                JOIN catalog.category cat ON cat.id = j.category_id AND cat.active
                LEFT JOIN catalog.category root ON root.id = cat.parent_id
                WHERE root.id IS NULL OR root.active
                GROUP BY cv.city, j.slug HAVING count(DISTINCT cv.artisan_id) >= :min
                ORDER BY 1, 2 NULLS FIRST
                SQL,
            ['meters' => 1000 * max(1, min($radiusKm, self::MAX_RADIUS_KM)), 'min' => max(0, $minPros)],
        );

        return array_map(static fn (array $r): array => ['city' => DbalValue::string($r['city']), 'job' => DbalValue::nullableString($r['job']), 'count' => DbalValue::int($r['n'])], $rows);
    }

    public function countsByDepartment(): array
    {
        $counts = [];
        foreach ($this->connection->fetchAllAssociative('SELECT department_code, count(*) AS n FROM search.artisan_search_view WHERE department_code IS NOT NULL GROUP BY department_code') as $r) {
            $counts[DbalValue::string($r['department_code'])] = DbalValue::int($r['n']);
        }

        return $counts;
    }

    public function publicArtisans(): array
    {
        return array_map(static fn (array $r): array => [
            'id' => DbalValue::string($r['artisan_id']),
            'name' => DbalValue::string($r['name']),
            'commune' => DbalValue::nullableString($r['commune_name']),
            'updated_at' => DbalValue::string($r['updated_at']),
        ], $this->connection->fetchAllAssociative('SELECT artisan_id, name, commune_name, updated_at FROM search.artisan_search_view ORDER BY artisan_id'));
    }

    public function directoryLetters(): array
    {
        return array_map(DbalValue::string(...), $this->connection->fetchFirstColumn(
            "SELECT DISTINCT CASE WHEN l ~ '^[A-Z]$' THEN l ELSE '#' END AS letter
             FROM (SELECT upper(left(catalog.immutable_unaccent(name), 1)) AS l FROM search.artisan_search_view) x ORDER BY letter",
        ));
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private static function uuids(array $ids): array
    {
        return array_values(array_filter($ids, static fn (string $id): bool => 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id)));
    }

    /**
     * @param array<string, mixed> $r
     * @param list<string>         $jobIds
     */
    private static function candidate(array $r, array $jobIds): SearchCandidate
    {
        return new SearchCandidate(
            DbalValue::string($r['artisan_id']),
            DbalValue::string($r['name']),
            null === $r['short_description'] ? null : DbalValue::string($r['short_description']),
            null === $r['main_job_id'] ? null : DbalValue::string($r['main_job_id']),
            null !== $r['main_job_id'] && \in_array(DbalValue::string($r['main_job_id']), $jobIds, true),
            null === $r['commune_name'] ? null : DbalValue::string($r['commune_name']),
            null === $r['department_code'] ? null : DbalValue::string($r['department_code']),
            round(is_numeric($r['distance_km']) ? (float) $r['distance_km'] : 0.0, 1),
            is_numeric($r['rating_avg']) ? (float) $r['rating_avg'] : null,
            DbalValue::int($r['reviews_count']),
            is_numeric($r['response_rate']) ? (float) $r['response_rate'] : null,
            DbalValue::int($r['completion']),
            true === $r['available_soon'],
            self::fromPgArray($r['labels']),
            true === $r['has_logo'],
            self::fromPgArray($r['job_ids']),
        );
    }

    /** @param list<string> $values */
    private static function pgArray(array $values): string
    {
        return '{'.implode(',', array_map(static fn (string $v): string => '"'.addcslashes($v, '"\\').'"', $values)).'}';
    }

    /** @return list<string> */
    private static function fromPgArray(mixed $value): array
    {
        $raw = trim(DbalValue::string($value), '{}');
        if ('' === $raw) {
            return [];
        }
        $values = str_getcsv($raw, ',', '"', '\\');

        return array_values(array_filter($values, \is_string(...)));
    }
}
