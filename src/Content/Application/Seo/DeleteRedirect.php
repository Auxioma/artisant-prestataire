<?php

declare(strict_types=1);

namespace App\Content\Application\Seo;

use App\Shared\Application\Bus\Command;

/** BO12.2 — supprime une redirection. */
final readonly class DeleteRedirect implements Command
{
    public function __construct(public string $adminId, public int $id)
    {
    }
}
