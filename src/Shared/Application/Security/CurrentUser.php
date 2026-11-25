<?php

declare(strict_types=1);

namespace App\Shared\Application\Security;

/**
 * Utilisateur authentifié, vu des modules métier (qui ne dépendent pas d'Identity). Implémenté par Identity.
 */
interface CurrentUser
{
    /** Identifiant (UUID) du compte connecté, null pour un visiteur. */
    public function id(): ?string;

    public function isArtisan(): bool;

    public function isEmailVerified(): bool;
}
