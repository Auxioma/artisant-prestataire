<?php

declare(strict_types=1);

namespace App\Identity\Domain\User;

/**
 * pending_email → active (A06) ; active ⇄ suspended (BO02) ; → deletion_requested (droit à l'effacement, annulable)
 * → anonymized (terminal, RGPD §11).
 */
enum UserStatus: string
{
    case PendingEmail = 'pending_email';
    case Active = 'active';
    case Suspended = 'suspended';
    case DeletionRequested = 'deletion_requested';
    case Anonymized = 'anonymized';

    /** Connexion possible : e-mail non vérifié compris (pour renvoyer le lien ; les actions restent bloquées, RG-040). */
    public function canLogIn(): bool
    {
        return self::Active === $this || self::PendingEmail === $this;
    }
}
