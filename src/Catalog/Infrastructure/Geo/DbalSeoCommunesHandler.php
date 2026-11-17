<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Geo;

use App\Catalog\Application\Geo\Query\CommuneSummary;
use App\Catalog\Application\Geo\Query\SeoCommune;
use App\Catalog\Application\Geo\Query\SeoCommuneBySlug;
use App\Catalog\Application\Geo\Query\SeoCommunes;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalSeoCommunesHandler implements QueryHandler
{
    private const string SELECT = 'SELECT c.insee, c.name, c.slug, c.postal_codes, c.department_code, c.population, d.name AS department_name, c.seo_page_enabled, c.seo_text
        FROM catalog.commune c JOIN catalog.department d ON d.code = c.department_code';
    private const int MAX_LIMIT = 50;

    public function __construct(private Connection $connection)
    {
    }

    /** @return SeoCommune|list<CommuneSummary>|null */
    public function __invoke(SeoCommuneBySlug|SeoCommunes $query): SeoCommune|array|null
    {
        if ($query instanceof SeoCommuneBySlug) {
            if (1 !== preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $query->slug)) {
                return null;
            }
            $row = $this->connection->fetchAssociative(self::SELECT.' WHERE c.slug = :slug AND c.active', ['slug' => $query->slug]);

            return false === $row ? null : new SeoCommune(self::summary($row), true === $row['seo_page_enabled'], DbalValue::nullableString($row['seo_text']));
        }
        $limit = max(1, min($query->limit, self::MAX_LIMIT));
        $rows = null !== $query->nearInsee
            ? $this->connection->fetchAllAssociative(
                self::SELECT.', catalog.commune o
                 WHERE o.insee = :insee AND c.insee <> o.insee AND c.active AND c.seo_page_enabled
                 ORDER BY c.centroid <-> o.centroid LIMIT :limit',
                ['insee' => $query->nearInsee, 'limit' => $limit],
            )
            : $this->connection->fetchAllAssociative(
                self::SELECT.' WHERE (CAST(:code AS text) IS NULL OR c.department_code = :code) AND c.active AND c.seo_page_enabled ORDER BY c.population DESC NULLS LAST, c.name LIMIT :limit',
                ['code' => $query->departmentCode, 'limit' => $limit],
            );

        return array_map(self::summary(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private static function summary(array $row): CommuneSummary
    {
        $postalCodes = trim(DbalValue::string($row['postal_codes']), '{}');

        return new CommuneSummary(
            DbalValue::string($row['insee']),
            DbalValue::string($row['name']),
            DbalValue::string($row['slug']),
            '' === $postalCodes ? [] : explode(',', $postalCodes),
            DbalValue::string($row['department_code']),
            null === $row['population'] ? null : DbalValue::int($row['population']),
            departmentName: DbalValue::string($row['department_name']),
        );
    }
}
