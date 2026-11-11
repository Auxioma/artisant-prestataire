<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Shared\Application\Bus\Command;

final readonly class CreateCategory implements Command
{
    public function __construct(
        public string $name,
        public string $icon,
        public ?string $slug = null,
        public ?string $parentSlug = null,
        public bool $featured = false,
        public ?string $description = null,
        public ?string $actorId = null,
    ) {
    }
}
