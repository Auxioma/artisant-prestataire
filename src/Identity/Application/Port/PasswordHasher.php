<?php

declare(strict_types=1);

namespace App\Identity\Application\Port;

/** Argon2id (paramètres dans config/packages/security.yaml — jamais l'algorithme « auto »). */
interface PasswordHasher
{
    public function hash(#[\SensitiveParameter] string $plain): string;

    public function verify(string $hash, #[\SensitiveParameter] string $plain): bool;

    /** Vrai si l'empreinte a été produite avec des paramètres plus faibles que les paramètres courants. */
    public function needsRehash(string $hash): bool;
}
