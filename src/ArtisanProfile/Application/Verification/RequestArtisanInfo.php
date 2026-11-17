<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Verification;

use App\Shared\Application\Bus\Command;

/** BO03 — demander une pièce ou une information complémentaire. */
final readonly class RequestArtisanInfo implements Command
{
    public function __construct(public string $profileId, public string $adminId, public string $message)
    {
    }
}
