<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Shared\Application\Bus\Command;

final readonly class DeactivateJob implements Command
{
    public function __construct(
        public string $slug,
        public ?string $replacementSlug = null,
        public ?string $actorId = null,
    ) {
    }
}
