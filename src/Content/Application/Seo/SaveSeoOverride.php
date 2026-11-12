<?php

declare(strict_types=1);

namespace App\Content\Application\Seo;

use App\Shared\Application\Bus\Command;

/** BO12.1 — surcharge du title, de la description ou de l'indexation d'une page précise. */
final readonly class SaveSeoOverride implements Command
{
    public function __construct(public string $adminId, public string $path, public ?string $title, public ?string $description, public bool $noindex)
    {
    }
}
