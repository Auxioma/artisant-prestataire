<?php

declare(strict_types=1);

namespace App\Shared\Application\Identity;

use App\Shared\Application\Bus\Command;

/**
 * Enregistre le téléphone d'un compte depuis un autre module (A02 : facultatif à l'inscription, requis au premier dépôt
 * de demande, P11.6). Traitée par Identity ; InvalidValue si le numéro est invalide.
 */
final readonly class ChangeUserPhone implements Command
{
    public function __construct(public string $userId, #[\SensitiveParameter] public string $phone)
    {
    }
}
