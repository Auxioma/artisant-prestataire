<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Draft;

use App\Shared\Application\Bus\Command;

/** P11.4 — retrait d'une pièce : le fichier est détruit. */
final readonly class RemoveDraftAttachment implements Command
{
    public function __construct(public DraftOwner $owner, public string $fileId)
    {
    }
}
