<?php

declare(strict_types=1);

namespace App\Identity\Application\Port;

/** RG-045 et A04 vus de la page de connexion : captcha requis ? page de retour admise ? */
interface LoginProtection
{
    public function captchaRequired(?string $email, ?string $ip): bool;

    /** Chemin interne admis pour le retour après connexion (liste blanche), null sinon. */
    public function safeTargetPath(?string $requested): ?string;
}
