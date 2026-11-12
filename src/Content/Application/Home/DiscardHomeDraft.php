<?php

declare(strict_types=1);

namespace App\Content\Application\Home;

use App\Shared\Application\Bus\Command;

/** BO10.1 — Abandonne le brouillon en cours. */
final readonly class DiscardHomeDraft implements Command
{
    public function __construct(public string $adminId)
    {
    }
}
