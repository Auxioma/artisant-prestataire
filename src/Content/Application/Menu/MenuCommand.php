<?php

declare(strict_types=1);

namespace App\Content\Application\Menu;

use App\Shared\Application\Bus\Command;

/** BO10.5 — action sur un élément : save, up, down, delete. */
final readonly class MenuCommand implements Command
{
    public const array ACTIONS = ['save', 'up', 'down', 'delete'];

    /** @param array<string, mixed> $data */
    public function __construct(public string $action, public string $id, public ?string $adminId, public array $data = [])
    {
    }
}
