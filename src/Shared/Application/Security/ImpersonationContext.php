<?php

declare(strict_types=1);

namespace App\Shared\Application\Security;

/** Connexion assistée de la requête en cours (null : l'utilisateur agit lui-même). */
interface ImpersonationContext
{
    /** Clé de session où la connexion assistée est notée à l'ouverture. */
    public const string SESSION_KEY = 'impersonation';

    public function current(): ?ImpersonationSession;
}
