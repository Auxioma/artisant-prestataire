<?php

declare(strict_types=1);

namespace App\Content\Application\Home;

use App\Shared\Application\Bus\Command;

/** BO10.1 — Publie le brouillon maintenant ou à la date indiquée (programmation). */
final readonly class PublishHome implements Command
{
    public function __construct(public string $adminId, public ?\DateTimeImmutable $publishAt = null)
    {
    }
}
