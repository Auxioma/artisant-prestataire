<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuse les comptes suspendus, en cours d'effacement ou anonymisés, avant même la vérification du mot de passe
 * (aucun calcul Argon2id inutile). Le message reste neutre ; le motif exact figure dans l'AuditLog.
 */
final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof SecurityUser && !$user->status->canLogIn()) {
            throw new CustomUserMessageAccountStatusException('Ce compte n’est pas accessible. Contactez le support si besoin.');
        }
    }

    public function checkPostAuth(UserInterface $user, mixed $token = null): void
    {
    }
}
