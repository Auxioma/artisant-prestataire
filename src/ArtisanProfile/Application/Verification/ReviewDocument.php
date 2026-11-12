<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Verification;

use App\Shared\Application\Bus\Command;

/** BO03 — valider (`approve`) ou refuser (`reject`, motif obligatoire) un justificatif. */
final readonly class ReviewDocument implements Command
{
    public function __construct(public string $profileId, public string $documentId, public string $adminId, public string $decision, public ?string $reason = null)
    {
    }
}
