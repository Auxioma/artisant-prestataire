<?php

declare(strict_types=1);

namespace App\Identity\Application\EmailVerification;

use App\Shared\Application\Bus\Query;

/**
 * P11.6 lien magique — le jeton d'activation qui vient d'être utilisé appartient-il à un compte actif créé sans mot de
 * passe ? Réponse : son adresse (identifiant de connexion), ou null.
 */
final readonly class PasswordlessLogin implements Query
{
    public function __construct(public string $token)
    {
    }
}
