<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

use App\Shared\Application\Bus\Command;

/**
 * BO10.2 — action sur une page : create, meta, add_block, save_block, move_up, move_down, remove_block, publish,
 * schedule, unpublish, delete. `data` : champs de la page ou du bloc ; `at` : date de programmation (ATOM).
 */
final readonly class PageCommand implements Command
{
    public const array ACTIONS = ['create', 'meta', 'add_block', 'save_block', 'move_up', 'move_down', 'remove_block', 'publish', 'schedule', 'unpublish', 'delete'];

    /** @param array<string, mixed> $data */
    public function __construct(public string $action, public string $pageId, public ?string $adminId, public array $data = [], public ?string $blockId = null, public ?string $at = null)
    {
    }
}
