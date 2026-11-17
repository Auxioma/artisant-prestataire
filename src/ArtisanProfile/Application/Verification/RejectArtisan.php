<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Verification;

use App\Shared\Application\Bus\Command;

/** BO03 — refuser le dossier : motif normalisé (RejectionReason), commentaire obligatoire pour « other ». */
final readonly class RejectArtisan implements Command
{
    public function __construct(public string $profileId, public string $adminId, public string $reason, public ?string $comment = null)
    {
    }
}
