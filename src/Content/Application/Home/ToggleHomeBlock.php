<?php

declare(strict_types=1);

namespace App\Content\Application\Home;

use App\Shared\Application\Bus\Command;

/** BO10.1 — Active ou désactive un bloc dans le brouillon. */
final readonly class ToggleHomeBlock implements Command
{
    public function __construct(public string $adminId, public string $type, public bool $enabled)
    {
    }
}
