<?php

declare(strict_types=1);

namespace App\Identity\Application\PasswordReset;

use App\Shared\Application\Bus\Query;

/** Adresse du titulaire d'un lien encore valide (connexion automatique après réinitialisation). Réponse : ?string. */
final readonly class ResetLinkHolder implements Query
{
    public function __construct(#[\SensitiveParameter] public string $token)
    {
    }
}
