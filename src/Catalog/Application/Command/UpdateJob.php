<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Shared\Application\Bus\Command;

/** BO06 : édition d'un métier ; un champ null n'est pas modifié. */
final readonly class UpdateJob implements Command
{
    /**
     * @param list<string>|null $synonyms
     */
    public function __construct(
        public string $slug,
        public ?string $name = null,
        public ?string $newSlug = null,
        public ?string $categorySlug = null,
        public ?array $synonyms = null,
        public ?int $position = null,
        public ?string $actorId = null,
    ) {
    }
}
