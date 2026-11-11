<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Type;

use App\Catalog\Domain\Category\CategoryId;
use App\Shared\Infrastructure\Doctrine\Type\AggregateIdType;

final class CategoryIdType extends AggregateIdType
{
    public const string NAME = 'catalog_category_id';

    protected function idClass(): string
    {
        return CategoryId::class;
    }
}
