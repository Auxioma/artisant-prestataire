<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class CategoryPage
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public string $icon,
        public ?string $description,
    ) {
    }
}
