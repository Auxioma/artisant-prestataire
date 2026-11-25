<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Query;

use App\Catalog\Application\Query\CatalogTree;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalCatalogTreeHandler implements QueryHandler
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<array{name: string, slug: string, root: bool, active: bool, featured: bool, icon: string, image: ?string, position: int, jobs: list<array{name: string, slug: string, active: bool, decennial: bool, synonyms: list<string>, proposed: bool, replaced_by: ?string, artisans: int}>}>
     */
    public function __invoke(CatalogTree $query): array
    {
        $jobsByCategory = [];
        foreach ($this->connection->fetchAllAssociative(<<<'SQL'
            SELECT j.category_id, j.name, j.slug, j.active, j.decennial_required, j.synonyms, r.name AS replaced_by,
                   EXISTS (SELECT 1 FROM catalog.job_proposal p WHERE p.job_id = j.id AND p.status = 'pending') AS proposed,
                   (SELECT count(*) FROM artisan.artisan_job aj WHERE aj.job_id = j.id) AS artisans
            FROM catalog.job j LEFT JOIN catalog.job r ON r.id = j.replaced_by
            ORDER BY j.position, j.name
            SQL) as $job) {
            $synonyms = DbalValue::json($job['synonyms']);
            $jobsByCategory[DbalValue::string($job['category_id'])][] = [
                'name' => DbalValue::string($job['name']),
                'slug' => DbalValue::string($job['slug']),
                'active' => true === $job['active'],
                'decennial' => true === $job['decennial_required'],
                'synonyms' => \is_array($synonyms) ? array_values(array_filter($synonyms, \is_string(...))) : [],
                'proposed' => true === $job['proposed'],
                'replaced_by' => DbalValue::nullableString($job['replaced_by']),
                'artisans' => DbalValue::int($job['artisans']),
            ];
        }

        $tree = [];
        foreach ($this->connection->fetchAllAssociative(<<<'SQL'
            SELECT c.id, c.parent_id, c.name, c.slug, c.icon, c.position, c.active, c.featured, c.image_media_id
            FROM catalog.category c LEFT JOIN catalog.category p ON p.id = c.parent_id
            ORDER BY COALESCE(p.position, c.position), COALESCE(c.parent_id, c.id), c.parent_id NULLS FIRST, c.position, c.name
            SQL) as $category) {
            $tree[] = [
                'name' => DbalValue::string($category['name']),
                'slug' => DbalValue::string($category['slug']),
                'root' => null === $category['parent_id'],
                'active' => true === $category['active'],
                'featured' => true === $category['featured'],
                'icon' => DbalValue::string($category['icon']),
                'image' => DbalValue::nullableString($category['image_media_id']),
                'position' => DbalValue::int($category['position']),
                'jobs' => $jobsByCategory[DbalValue::string($category['id'])] ?? [],
            ];
        }

        return $tree;
    }
}
