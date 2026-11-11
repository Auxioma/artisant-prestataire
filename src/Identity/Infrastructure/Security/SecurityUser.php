<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Domain\User\User;
use App\Identity\Domain\User\UserKind;
use App\Identity\Domain\User\UserStatus;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Représentation de session, découplée de l'agrégat (aucune entité Doctrine dans la session).
 * isEqualTo : un changement de mot de passe, d'e-mail ou de statut ferme les sessions ouvertes au rechargement suivant.
 */
final class SecurityUser implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    public const string ROLE_CLIENT = 'ROLE_CLIENT';
    public const string ROLE_ARTISAN = 'ROLE_ARTISAN';
    /** Accordé aux seuls comptes actifs à l'e-mail vérifié (RG-040 : actions bloquées sinon). */
    public const string ROLE_VERIFIED = 'ROLE_VERIFIED';

    /** Empreinte de l'empreinte : seule valeur liée au mot de passe conservée en session. */
    private string $passwordFingerprint;

    /**
     * @param non-empty-string $email
     */
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        private string $passwordHash,
        public readonly UserKind $kind,
        public readonly UserStatus $status,
        public readonly bool $emailVerified,
    ) {
        $this->passwordFingerprint = hash('sha256', $passwordHash);
    }

    public static function fromUser(User $user): self
    {
        $email = $user->email()->value;
        \assert('' !== $email);

        return new self($user->id()->value, $email, $user->passwordHash(), $user->kind(), $user->status(), $user->isEmailVerified());
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        $roles = ['ROLE_USER', UserKind::Artisan === $this->kind ? self::ROLE_ARTISAN : self::ROLE_CLIENT];
        if ($this->emailVerified && UserStatus::Active === $this->status) {
            $roles[] = self::ROLE_VERIFIED;
        }

        return $roles;
    }

    /** Vide pour un utilisateur restauré depuis la session : l'authentification recharge toujours le compte. */
    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function eraseCredentials(): void
    {
    }

    /**
     * Comparaison session ↔ compte rechargé : un changement de mot de passe, d'e-mail ou de statut ferme les sessions
     * ouvertes dès la requête suivante (ASVS 3.3.3).
     */
    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self
            && $user->id === $this->id
            && $user->email === $this->email
            && hash_equals($this->passwordFingerprint, $user->passwordFingerprint)
            && $user->status === $this->status
            && $user->emailVerified === $this->emailVerified;
    }

    /** @return array{id: string, email: string, fingerprint: string, kind: string, status: string, verified: bool} */
    public function __serialize(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'fingerprint' => $this->passwordFingerprint,
            'kind' => $this->kind->value,
            'status' => $this->status->value,
            'verified' => $this->emailVerified,
        ];
    }

    /** @param array{id: string, email: non-empty-string, fingerprint: string, kind: string, status: string, verified: bool} $data */
    public function __unserialize(array $data): void
    {
        $this->id = $data['id'];
        $this->email = $data['email'];
        $this->passwordHash = '';
        $this->passwordFingerprint = $data['fingerprint'];
        $this->kind = UserKind::from($data['kind']);
        $this->status = UserStatus::from($data['status']);
        $this->emailVerified = $data['verified'];
    }
}
