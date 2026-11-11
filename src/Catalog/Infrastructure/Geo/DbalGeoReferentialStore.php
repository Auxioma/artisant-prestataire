<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Geo;

use App\Catalog\Application\Geo\Port\GeoReferentialStore;
use App\Shared\Domain\ValueObject\Slug;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalGeoReferentialStore implements GeoReferentialStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function upsertRegions(array $regions): void
    {
        foreach ($regions as $region) {
            $this->connection->executeStatement(
                'INSERT INTO catalog.region (code, name, slug) VALUES (:code, :name, :slug)
                 ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name',
                ['code' => $region->code, 'name' => $region->name, 'slug' => Slug::fromText($region->name)->value],
            );
        }
    }

    public function upsertDepartments(array $departments): void
    {
        foreach ($departments as $department) {
            $this->connection->executeStatement(
                'INSERT INTO catalog.department (code, name, slug, region_code) VALUES (:code, :name, :slug, :region)
                 ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, region_code = EXCLUDED.region_code',
                ['code' => $department->code, 'name' => $department->name, 'slug' => Slug::fromText($department->name.' '.$department->code)->value, 'region' => $department->regionCode],
            );
        }
    }

    public function upsertCommunes(array $communes): int
    {
        if ([] === $communes) {
            return 0;
        }
        $values = [];
        $params = [];
        foreach ($communes as $i => $commune) {
            $values[] = \sprintf('(:insee%1$d, :name%1$d, :slug%1$d, CAST(:cp%1$d AS text[]), :dep%1$d, CAST(:pop%1$d AS integer), ST_SetSRID(ST_MakePoint(:lng%1$d, :lat%1$d), 4326)::geography)', $i);
            $params += [
                "insee$i" => $commune->insee, "name$i" => $commune->name, "slug$i" => $commune->slug,
                "cp$i" => '{'.implode(',', $commune->postalCodes).'}', "dep$i" => $commune->departmentCode,
                "pop$i" => $commune->population, "lng$i" => $commune->longitude, "lat$i" => $commune->latitude,
            ];
        }

        // xmax = 0 : ligne insérée (et non mise à jour) — compte les communes nouvelles.
        $inserted = $this->connection->fetchAllAssociative(
            'INSERT INTO catalog.commune (insee, name, slug, postal_codes, department_code, population, centroid) VALUES '.implode(', ', $values).'
             ON CONFLICT (insee) DO UPDATE SET name = EXCLUDED.name, postal_codes = EXCLUDED.postal_codes, department_code = EXCLUDED.department_code,
                 population = EXCLUDED.population, centroid = EXCLUDED.centroid, active = true, updated_at = now()
             RETURNING (xmax = 0) AS inserted',
            $params,
        );

        return \count(array_filter($inserted, static fn (array $row): bool => true === $row['inserted']));
    }

    public function slugOwners(): array
    {
        $owners = [];
        foreach ($this->connection->fetchAllKeyValue('SELECT slug, insee FROM catalog.commune') as $slug => $insee) {
            $owners[DbalValue::string($slug)] = DbalValue::string($insee);
        }

        return $owners;
    }

    public function activeCommuneCount(): int
    {
        return DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM catalog.commune WHERE active'));
    }

    public function deactivateMissing(array $presentInsee): int
    {
        return (int) $this->connection->executeStatement(
            'UPDATE catalog.commune SET active = false, updated_at = now() WHERE active AND NOT (insee = ANY(CAST(:present AS text[])))',
            ['present' => '{'.implode(',', $presentInsee).'}'],
        );
    }

    public function enableSeoPages(int $minPopulation): int
    {
        return (int) $this->connection->executeStatement(
            'UPDATE catalog.commune SET seo_page_enabled = true, updated_at = now() WHERE active AND NOT seo_page_enabled AND population >= :min',
            ['min' => $minPopulation],
        );
    }
}
