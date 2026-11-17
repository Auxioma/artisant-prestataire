<?php

declare(strict_types=1);

namespace App\Administration\Infrastructure\Security;

use App\Administration\Domain\Admin\AdminRole;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Administrateur authentifié (pare-feu `admin`). Le secret TOTP déchiffré et l'empreinte du mot de passe ne vivent
 * qu'en mémoire : la session ne conserve que des empreintes (ASVS 3.3.3) ; le compte est rechargé à chaque requête,
 * et un changement de mot de passe, de rôle ou de statut ferme la session (isEqualTo).
 */
final class AdminSecurityUser implements UserInterface, PasswordAuthenticatedUserInterface, TwoFactorInterface, EquatableInterface
{
    public const string ROLE_ADMIN = 'ROLE_ADMIN';
    private const int TOTP_PERIOD = 30;
    private const int TOTP_DIGITS = 6;

    private string $passwordFingerprint;

    /**
     * @param non-empty-string $email
     * @param list<string>     $backupCodeHashes
     */
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly string $name,
        public readonly AdminRole $role,
        private string $passwordHash,
        #[\SensitiveParameter]
        private ?string $totpSecret,
        private array $backupCodeHashes,
    ) {
        $this->passwordFingerprint = hash('sha256', $passwordHash);
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        return [self::ROLE_ADMIN, $this->role->securityRole()];
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function eraseCredentials(): void
    {
    }

    public function isTotpAuthenticationEnabled(): bool
    {
        return null !== $this->totpSecret && '' !== $this->totpSecret;
    }

    public function getTotpAuthenticationUsername(): string
    {
        return $this->email;
    }

    public function getTotpAuthenticationConfiguration(): ?TotpConfigurationInterface
    {
        return null === $this->totpSecret ? null : new TotpConfiguration($this->totpSecret, TotpConfiguration::ALGORITHM_SHA1, self::TOTP_PERIOD, self::TOTP_DIGITS);
    }

    /** @return list<string> */
    public function backupCodeHashes(): array
    {
        return $this->backupCodeHashes;
    }

    public function can(string $permission): bool
    {
        return $this->role->allows($permission);
    }

    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self
            && $user->id === $this->id
            && $user->email === $this->email
            && $user->role === $this->role
            && hash_equals($this->passwordFingerprint, $user->passwordFingerprint);
    }

    /** @return array{id: string, email: string, name: string, role: string, fingerprint: string} */
    public function __serialize(): array
    {
        return ['id' => $this->id, 'email' => $this->email, 'name' => $this->name, 'role' => $this->role->value, 'fingerprint' => $this->passwordFingerprint];
    }

    /** @param array{id: string, email: non-empty-string, name: string, role: string, fingerprint: string} $data */
    public function __unserialize(array $data): void
    {
        $this->id = $data['id'];
        $this->email = $data['email'];
        $this->name = $data['name'];
        $this->role = AdminRole::from($data['role']);
        $this->passwordHash = '';
        $this->passwordFingerprint = $data['fingerprint'];
        $this->totpSecret = null;
        $this->backupCodeHashes = [];
    }
}
