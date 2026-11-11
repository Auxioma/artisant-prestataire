<?php

declare(strict_types=1);

namespace App\Identity\Application\EmailVerification;

/** Issues affichées par A06.1 : succès, lien expiré (renvoyer), déjà validé (se connecter), lien invalide. */
enum EmailTokenState: string
{
    case Valid = 'valid';
    case Expired = 'expired';
    case AlreadyVerified = 'already_verified';
    case Invalid = 'invalid';
}
