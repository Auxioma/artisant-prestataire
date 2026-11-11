<?php

declare(strict_types=1);

namespace App\Identity\Application\Port;

/** Have I Been Pwned (k-anonymat : seuls les 5 premiers caractères du SHA-1 quittent le serveur). */
interface CompromisedPasswordChecker
{
    /** Faux si le service est indisponible : une panne externe ne doit pas bloquer l'inscription. */
    public function isCompromised(#[\SensitiveParameter] string $plain): bool;
}
