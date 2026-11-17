<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Signup;

use App\Shared\Application\Bus\Query;

/** État du tunnel A03 pour le compte connecté. Réponse : SignupState. */
final readonly class SignupProgress implements Query
{
    public function __construct(public string $userId)
    {
    }
}
