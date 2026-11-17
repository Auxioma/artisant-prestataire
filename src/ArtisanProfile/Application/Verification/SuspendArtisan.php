<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Verification;

use App\Shared\Application\Bus\Command;

/** Suspendre un compte pro (adminId null : décision automatique). */
final readonly class SuspendArtisan implements Command
{
    public function __construct(public string $profileId, public ?string $adminId, public string $reason)
    {
    }
}
