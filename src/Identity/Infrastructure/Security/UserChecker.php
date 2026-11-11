<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuse les comptes suspendus, en cours d'effacement ou anonymisés APRÈS la vérification du mot de passe : contrôlé
 * avant, il révélerait à n'importe qui qu'un compte (suspendu) existe pour une adresse donnée (anti-énumération, A04).
 */
final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
    }

    public function checkPostAuth(UserInterface $user, mixed $token = null): void
    {
        if ($user instanceof SecurityUser && !$user->status->canLogIn()) {
            throw new CustomUserMessageAccountStatusException('Ce compte n’est pas accessible. Contactez le support si besoin.');
        }
    }
}
