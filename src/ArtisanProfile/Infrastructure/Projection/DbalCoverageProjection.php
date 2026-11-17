<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Infrastructure\Projection;

use App\ArtisanProfile\Application\Port\CoverageProjection;
use App\ArtisanProfile\Domain\Profile\ServiceArea;
use App\ArtisanProfile\Domain\Profile\ServiceAreaMode;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/** Requêtes PostGIS paramétrées (cadrage §5.3) sur le référentiel `catalog.commune` (centroïdes, RG-022). */
final readonly class DbalCoverageProjection implements CoverageProjection
{
    public function __construct(private Connection $connection)
    {
    }

    public function replaceJobs(string $profileId, array $jobs): void
    {
        $this->connection->executeStatement('DELETE FROM artisan.artisan_job WHERE artisan_id = :id', ['id' => $profileId]);
        foreach ($jobs as $job) {
            $this->connection->executeStatement(
                'INSERT INTO artisan.artisan_job (artisan_id, job_id, is_main) VALUES (:id, :job, :main)',
                ['id' => $profileId, 'job' => $job['job'], 'main' => $job['main']],
                ['main' => 'boolean'],
            );
        }
    }

    public function replaceArea(string $profileId, ?ServiceArea $area): int
    {
        $this->connection->executeStatement('DELETE FROM artisan.service_area_commune WHERE artisan_id = :id', ['id' => $profileId]);
        if (null === $area) {
            return 0;
        }
        if (ServiceAreaMode::Radius === $area->mode) {
            return (int) $this->connection->executeStatement(
                'INSERT INTO artisan.service_area_commune (artisan_id, insee)
                 SELECT :id, c.insee FROM catalog.commune o
                 JOIN catalog.commune c ON c.active AND ST_DWithin(c.centroid, o.centroid, :meters)
                 WHERE o.insee = :center',
                ['id' => $profileId, 'center' => $area->centerInsee, 'meters' => 1000 * (int) $area->radiusKm],
            );
        }

        return (int) $this->connection->executeStatement(
            'INSERT INTO artisan.service_area_commune (artisan_id, insee)
             SELECT :id, insee FROM catalog.commune WHERE active AND (department_code IN (:departments) OR insee IN (:communes))',
            // IN () n'est pas du SQL valide : liste vide remplacée par une valeur impossible.
            ['id' => $profileId, 'departments' => [] === $area->departments ? [''] : $area->departments, 'communes' => [] === $area->communes ? [''] : $area->communes],
            ['departments' => ArrayParameterType::STRING, 'communes' => ArrayParameterType::STRING],
        );
    }

    public function rebuildAll(): int
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, jobs, area_mode, area_center_insee, area_radius_km, area_departments, area_communes FROM artisan.artisan_profile');
        foreach ($rows as $row) {
            $id = DbalValue::string($row['id']);
            $jobs = DbalValue::json($row['jobs']);
            $assignments = [];
            foreach (\is_array($jobs) ? $jobs : [] as $job) {
                if (\is_array($job) && \is_string($job['job'] ?? null) && \is_bool($job['main'] ?? null)) {
                    $assignments[] = ['job' => $job['job'], 'main' => $job['main']];
                }
            }
            $this->replaceJobs($id, $assignments);
            $mode = null === $row['area_mode'] ? null : ServiceAreaMode::from(DbalValue::string($row['area_mode']));
            $area = null === $mode ? null : ServiceArea::restore(
                $mode,
                null === $row['area_center_insee'] ? null : DbalValue::string($row['area_center_insee']),
                null === $row['area_radius_km'] ? null : DbalValue::int($row['area_radius_km']),
                self::strings($row['area_departments']),
                self::strings($row['area_communes']),
            );
            $this->replaceArea($id, $area);
        }

        return \count($rows);
    }

    /** @return list<string> */
    private static function strings(mixed $json): array
    {
        $values = DbalValue::json($json);

        return array_values(array_filter(\is_array($values) ? $values : [], \is_string(...)));
    }
}
