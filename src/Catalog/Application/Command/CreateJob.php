<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Shared\Application\Bus\Command;

final readonly class CreateJob implements Command
{
    /**
     * @param list<string> $synonyms
     * @param list<string> $requiredDocuments
     */
    public function __construct(
        public string $categorySlug,
        public string $name,
        public ?string $slug = null,
        public array $synonyms = [],
        public array $requiredDocuments = [],
        public bool $insuranceRequired = true,
        public bool $decennialRequired = false,
        public ?string $actorId = null,
    ) {
    }
}
