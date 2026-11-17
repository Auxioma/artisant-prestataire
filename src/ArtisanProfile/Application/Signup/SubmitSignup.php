<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Signup;

use App\Shared\Application\Bus\Command;

/** A03.7 — « Finaliser mon inscription » : description courte puis envoi du dossier (RG-043). */
final readonly class SubmitSignup implements Command
{
    public function __construct(public string $userId, public string $shortDescription)
    {
    }
}
