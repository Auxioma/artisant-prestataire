<?php

declare(strict_types=1);

namespace App\Content\Application\Media;

use App\Shared\Application\Bus\Command;

/** Suppression : l'original et toutes ses variantes sont détruits. */
final readonly class DeleteMedia implements Command
{
    public function __construct(public string $mediaId, public ?string $adminId)
    {
    }
}
