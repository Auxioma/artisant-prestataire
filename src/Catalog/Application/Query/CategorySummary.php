<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class CategorySummary
{
    /**
     * @param list<array{name: string, slug: string}> $jobs
     */
    public function __construct(
        public string $name,
        public string $slug,
        public string $icon,
        public int $activeJobCount,
        public array $jobs,
    ) {
    }
}
