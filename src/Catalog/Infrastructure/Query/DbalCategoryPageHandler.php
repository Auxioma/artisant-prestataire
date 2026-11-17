<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Query;

use App\Catalog\Application\Query\CategoryBySlug;
use App\Catalog\Application\Query\CategoryPage;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalCategoryPageHandler implements QueryHandler
{
    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(CategoryBySlug $query): ?CategoryPage
    {
        if (1 !== preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $query->slug)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            'SELECT id, name, slug, icon, description FROM catalog.category WHERE slug = :slug AND parent_id IS NULL AND active',
            ['slug' => $query->slug],
        );

        return false === $row ? null : new CategoryPage(
            DbalValue::string($row['id']),
            DbalValue::string($row['name']),
            DbalValue::string($row['slug']),
            DbalValue::string($row['icon']),
            DbalValue::nullableString($row['description']),
        );
    }
}
