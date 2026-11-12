<?php

declare(strict_types=1);

namespace App\Administration\Infrastructure\Security;

use App\Administration\Domain\Admin\AdminUser;
use App\Administration\Domain\Admin\AdminUserRepository;
use App\Shared\Application\Security\SecretBox;
use App\Shared\Domain\ValueObject\Email;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Fournisseur du pare-feu `admin`. Seuls les comptes actifs dont le mot de passe ET la 2FA sont configurés sont
 * chargés (un compte invité ou désactivé n'existe pas pour l'authentification, réponse identique).
 *
 * @implements UserProviderInterface<AdminSecurityUser>
 */
final readonly class AdminUserProvider implements UserProviderInterface
{
    public function __construct(private AdminUserRepository $admins, private SecretBox $secrets)
    {
    }

    public function loadUserByIdentifier(string $identifier): AdminSecurityUser
    {
        try {
            $admin = $this->admins->findByEmail(new Email($identifier));
        } catch (\InvalidArgumentException) {
            $admin = null;
        }

        return $this->securityUser($admin, $identifier);
    }

    public function refreshUser(UserInterface $user): AdminSecurityUser
    {
        if (!$user instanceof AdminSecurityUser) {
            throw new UnsupportedUserException();
        }

        return $this->securityUser($this->admins->find($user->id), $user->email);
    }

    public function supportsClass(string $class): bool
    {
        return AdminSecurityUser::class === $class;
    }

    private function securityUser(?AdminUser $admin, string $identifier): AdminSecurityUser
    {
        if (null === $admin || !$admin->canSignIn()) {
            $e = new UserNotFoundException();
            $e->setUserIdentifier($identifier);
            throw $e;
        }
        $email = $admin->email()->value;
        \assert('' !== $email);
        $sealed = $admin->sealedTotpSecret();
        \assert(null !== $sealed);

        return new AdminSecurityUser($admin->id(), $email, $admin->name(), $admin->role(), (string) $admin->passwordHash(), $this->secrets->open($sealed), $admin->backupCodeHashesForCheck());
    }
}
