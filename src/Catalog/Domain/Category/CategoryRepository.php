<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Category;

use App\Shared\Domain\ValueObject\Slug;

interface CategoryRepository
{
    public function get(CategoryId $id): Category;

    public function findBySlug(Slug $slug): ?Category;

    public function add(Category $category): void;

    /** Position suivante parmi les enfants de $parent (ou les catégories racines). */
    public function nextPosition(?CategoryId $parent): int;
}
