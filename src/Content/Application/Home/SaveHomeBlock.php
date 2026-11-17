<?php

declare(strict_types=1);

namespace App\Content\Application\Home;

use App\Shared\Application\Bus\Command;

/** BO10.1 — Enregistre un bloc dans le brouillon (créé à partir de la version en ligne au besoin). */
final readonly class SaveHomeBlock implements Command
{
    /**
     * @param array<array-key, mixed> $input
     */
    public function __construct(public string $adminId, public string $type, public bool $enabled, public array $input)
    {
    }
}
