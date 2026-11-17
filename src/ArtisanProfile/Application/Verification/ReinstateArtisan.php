<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Verification;

use App\Shared\Application\Bus\Command;

/** Lever la suspension d'un compte pro. */
final readonly class ReinstateArtisan implements Command
{
    public function __construct(public string $profileId, public ?string $adminId)
    {
    }
}
