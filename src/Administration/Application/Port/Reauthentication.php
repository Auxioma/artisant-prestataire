<?php

declare(strict_types=1);

namespace App\Administration\Application\Port;

/** ADR-0013 — ré-authentification (mot de passe + code TOTP) exigée pour les actions sensibles. */
interface Reauthentication
{
    public const string ATTRIBUTE = 'ADMIN_RECENT_AUTH';

    /** Vérifie mot de passe et code de l'administrateur connecté ; succès = ré-authentification fraîche. */
    public function attempt(#[\SensitiveParameter] string $password, string $code): bool;

    public function isRecent(): bool;
}
