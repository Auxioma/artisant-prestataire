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
     * @return list<array{name: string, slug: string, root: bool, active: bool, featured: bool, jobs: list<array{name: string, slug: string, active: bool, decennial: bool, synonyms: list<string>}>}>
     */
    public function __invoke(CatalogTree $query): array
    {
        $jobsByCategory = [];
        foreach ($this->connection->fetchAllAssociative('SELECT category_id, name, slug, active, decennial_required, synonyms FROM catalog.job ORDER BY position, name') as $job) {
            $synonyms = DbalValue::json($job['synonyms']);
            $jobsByCategory[DbalValue::string($job['category_id'])][] = [
                'name' => DbalValue::string($job['name']),
                'slug' => DbalValue::string($job['slug']),
                'active' => true === $job['active'],
                'decennial' => true === $job['decennial_required'],
                'synonyms' => \is_array($synonyms) ? array_values(array_filter($synonyms, \is_string(...))) : [],
            ];
        }

        $tree = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id, parent_id, name, slug, active, featured FROM catalog.category ORDER BY COALESCE(parent_id, id), parent_id NULLS FIRST, position, name') as $category) {
            $tree[] = [
                'name' => DbalValue::string($category['name']),
                'slug' => DbalValue::string($category['slug']),
                'root' => null === $category['parent_id'],
                'active' => true === $category['active'],
                'featured' => true === $category['featured'],
                'jobs' => $jobsByCategory[DbalValue::string($category['id'])] ?? [],
            ];
        }

        return $tree;
    }
}
