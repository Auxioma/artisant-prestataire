<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Query;

use App\Catalog\Application\Query\CategorySummary;
use App\Catalog\Application\Query\MainCategories;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalMainCategoriesHandler implements QueryHandler
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<CategorySummary>
     */
    public function __invoke(MainCategories $query): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            WITH root AS (
                SELECT id, name, slug, icon, position FROM catalog.category
                WHERE parent_id IS NULL AND active AND (NOT :featured_only OR featured)
            ), active_jobs AS (
                SELECT r.id AS root_id, j.name, j.slug,
                       row_number() OVER (PARTITION BY r.id ORDER BY (j.category_id <> r.id), COALESCE(sub.position, -1), j.position, j.name) AS rank,
                       count(*) OVER (PARTITION BY r.id) AS total
                FROM root r
                LEFT JOIN catalog.category sub ON sub.parent_id = r.id AND sub.active
                JOIN catalog.job j ON j.active AND (j.category_id = r.id OR j.category_id = sub.id)
            )
            SELECT r.name, r.slug, r.icon, r.position, aj.total, aj.rank, aj.name AS job_name, aj.slug AS job_slug
            FROM root r JOIN active_jobs aj ON aj.root_id = r.id
            WHERE aj.rank <= :limit
            ORDER BY r.position, r.name, aj.rank
            SQL, ['featured_only' => $query->featuredOnly, 'limit' => $query->jobsPerCategory], ['featured_only' => \Doctrine\DBAL\ParameterType::BOOLEAN]);

        $categories = [];
        foreach ($rows as $row) {
            $slug = DbalValue::string($row['slug']);
            $categories[$slug] ??= ['name' => DbalValue::string($row['name']), 'icon' => DbalValue::string($row['icon']), 'total' => DbalValue::int($row['total']), 'jobs' => []];
            $categories[$slug]['jobs'][] = ['name' => DbalValue::string($row['job_name']), 'slug' => DbalValue::string($row['job_slug'])];
        }

        $summaries = [];
        foreach ($categories as $slug => $category) {
            $summaries[] = new CategorySummary($category['name'], (string) $slug, $category['icon'], $category['total'], $category['jobs']);
        }

        return $summaries;
    }
}
