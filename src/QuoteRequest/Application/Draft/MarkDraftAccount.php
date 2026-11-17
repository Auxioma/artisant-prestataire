<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Draft;

use App\Shared\Application\Bus\Command;

/** P11.6 — compte créé dans le tunnel pour ce brouillon de visiteur (identifiant réservé, existence non révélée). */
final readonly class MarkDraftAccount implements Command
{
    public function __construct(public DraftOwner $owner, public string $userId)
    {
    }
}
