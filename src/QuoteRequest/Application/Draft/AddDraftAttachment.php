<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Draft;

use App\Shared\Application\Bus\Command;

/** P11.4 — photo ou PDF ajouté au brouillon (RG-061 : nombre et poids bornés, ré-encodage sans EXIF, antivirus). */
final readonly class AddDraftAttachment implements Command
{
    public function __construct(public DraftOwner $owner, public string $draftId, public string $path, public ?string $clientName)
    {
    }
}
