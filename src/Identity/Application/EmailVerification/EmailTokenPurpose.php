<?php

declare(strict_types=1);

namespace App\Identity\Application\EmailVerification;

enum EmailTokenPurpose: string
{
    /** E-01 : confirmation de l'adresse à l'inscription. */
    case Signup = 'signup';
    /** Changement d'adresse : confirmation de la nouvelle adresse. */
    case EmailChange = 'email_change';
}
