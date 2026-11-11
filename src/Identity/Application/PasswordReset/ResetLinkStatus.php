<?php

declare(strict_types=1);

namespace App\Identity\Application\PasswordReset;

use App\Shared\Application\Bus\Query;

/** État d'un lien de réinitialisation avant affichage du formulaire. Réponse : ResetLinkState. */
final readonly class ResetLinkStatus implements Query
{
    public function __construct(#[\SensitiveParameter] public string $token)
    {
    }
}
