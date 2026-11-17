<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserRepository;
use App\Shared\Domain\Exception\InvalidValue;
use App\Shared\Domain\ValueObject\Email;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Charge les comptes clients et artisans (les administrateurs ont leur propre fournisseur et pare-feu, ADR-0013).
 *
 * @implements UserProviderInterface<SecurityUser>
 */
final readonly class UserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(private UserRepository $users, private EntityManagerInterface $em)
    {
    }

    public function loadUserByIdentifier(string $identifier): SecurityUser
    {
        try {
            $email = new Email($identifier);
        } catch (InvalidValue) {
            throw new UserNotFoundException();
        }

        $user = $this->users->findByEmail($email) ?? throw new UserNotFoundException();

        return SecurityUser::fromUser($user);
    }

    public function refreshUser(UserInterface $user): SecurityUser
    {
        if (!$user instanceof SecurityUser) {
            throw new UnsupportedUserException(\sprintf('Utilisateur non pris en charge : %s.', $user::class));
        }
        $fresh = $this->users->find(new UserId($user->id)) ?? throw new UserNotFoundException();

        return SecurityUser::fromUser($fresh);
    }

    public function supportsClass(string $class): bool
    {
        return SecurityUser::class === $class;
    }

    /** Ré-hachage transparent quand les paramètres Argon2id sont renforcés. */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof SecurityUser) {
            return;
        }
        $account = $this->users->find(new UserId($user->id));
        if (null === $account) {
            return;
        }
        $account->upgradePasswordHash($newHashedPassword);
        $this->em->flush();
    }
}
