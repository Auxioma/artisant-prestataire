<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Signup;

use App\Shared\Application\Bus\Command;

/** A03.6 — « Continuer » (pièces obligatoires déposées) ou « Terminer plus tard » (`later` : vérification en attente des pièces). */
final readonly class CompleteDocumentsStep implements Command
{
    public function __construct(public string $userId, public bool $later)
    {
    }
}
