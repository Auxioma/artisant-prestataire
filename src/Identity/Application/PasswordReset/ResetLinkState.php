<?php

declare(strict_types=1);

namespace App\Identity\Application\PasswordReset;

/** A05.2 : lien valide, ou expiré / déjà utilisé / inconnu (même écran « Ce lien a expiré » pour ces trois cas). */
enum ResetLinkState: string
{
    case Valid = 'valid';
    case Unusable = 'unusable';
}
