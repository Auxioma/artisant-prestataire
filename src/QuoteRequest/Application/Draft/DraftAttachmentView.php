<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Draft;

final readonly class DraftAttachmentView
{
    /** @param string $status statut du fichier (quarantined / available / rejected) */
    public function __construct(public string $fileId, public string $name, public string $status, public bool $image)
    {
    }
}
