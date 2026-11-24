<?php

declare(strict_types=1);

namespace App\Content\Application\Blog;

use App\Shared\Application\Bus\Command;

/** BO10.3 — actions du blog sur un article (`$id`) ou une catégorie (actions `*_category`). */
final readonly class BlogCommand implements Command
{
    public const array ACTIONS = ['create', 'save', 'publish', 'schedule', 'unpublish', 'delete', 'create_category', 'save_category', 'delete_category'];

    /** @param array<string, mixed> $data */
    public function __construct(public string $action, public string $id, public ?string $adminId, public array $data = [], public ?string $at = null)
    {
    }
}
