<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class JobIndexEntry
{
    /**
     * @param list<string> $synonyms
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public array $synonyms,
        public string $categoryName,
        public string $categorySlug,
        public bool $searchable,
        public ?string $description = null,
    ) {
    }
}
