<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Shared\Application\Bus\Command;

/** BO06 : édition d'une catégorie ; un champ null n'est pas modifié. */
final readonly class UpdateCategory implements Command
{
    public function __construct(
        public string $slug,
        public ?string $name = null,
        public ?string $newSlug = null,
        public ?string $icon = null,
        public ?bool $active = null,
        public ?bool $featured = null,
        public ?int $position = null,
        public ?string $actorId = null,
        /** Image de la médiathèque : null = inchangée, '' = retirée, sinon identifiant de l'image. */
        public ?string $imageMediaId = null,
    ) {
    }
}
