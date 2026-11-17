<?php

declare(strict_types=1);

namespace App\Search\Application\Port;

final readonly class JobDocument
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
    ) {
    }
}
