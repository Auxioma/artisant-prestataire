<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Query;

use App\Catalog\Application\Query\CategoriesUsingMedia;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalCategoriesUsingMediaHandler implements QueryHandler
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<string> */
    public function __invoke(CategoriesUsingMedia $query): array
    {
        return array_map(
            static fn (mixed $name): string => 'Catégorie « '.DbalValue::string($name).' »',
            $this->connection->fetchFirstColumn('SELECT name FROM catalog.category WHERE image_media_id = :id ORDER BY name', ['id' => $query->mediaId]),
        );
    }
}
