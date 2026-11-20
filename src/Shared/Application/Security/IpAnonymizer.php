<?php

declare(strict_types=1);

namespace App\Shared\Application\Security;

/** Pseudonymise une adresse IP avant stockage (data-model §4 : IP hachées hors journal de sécurité). */
interface IpAnonymizer
{
    public function hash(?string $ip): ?string;
}
