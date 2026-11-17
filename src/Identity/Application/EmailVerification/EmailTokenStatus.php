<?php

declare(strict_types=1);

namespace App\Identity\Application\EmailVerification;

use App\Shared\Application\Bus\Query;

/** État d'un lien de validation, avant confirmation (A06). Réponse : EmailTokenState. */
final readonly class EmailTokenStatus implements Query
{
    public function __construct(public string $token)
    {
    }
}
