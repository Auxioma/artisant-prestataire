<?php

declare(strict_types=1);

namespace App\Identity\Domain\User;

use App\Identity\Domain\User\Event\UserAnonymized;
use App\Identity\Domain\User\Event\UserDeletionRequested;
use App\Identity\Domain\User\Event\UserEmailChanged;
use App\Identity\Domain\User\Event\UserEmailVerified;
use App\Identity\Domain\User\Event\UserPasswordChanged;
use App\Identity\Domain\User\Event\UserRegistered;
use App\Identity\Domain\User\Event\UserReinstated;
use App\Identity\Domain\User\Event\UserSuspended;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\ValueObject\Email;
use App\Shared\Domain\ValueObject\PhoneNumber;

/**
 * Compte utilisateur (client ou artisan). Le domaine ne voit que l'empreinte du mot de passe, jamais le mot de passe.
 * L'anonymisation est terminale : plus aucune opération n'est possible ensuite.
 */
final class User extends AggregateRoot
{
    /** Empreinte inutilisable (aucun hachage valide ne commence par « ! ») : connexion impossible après anonymisation. */
    private const string UNUSABLE_PASSWORD = '!anonymized';
    private const string ANONYMIZED_DOMAIN = 'anonymise.invalid';

    private string $email;
    private string $emailCanonical;
    private UserStatus $status = UserStatus::PendingEmail;
    private string $firstName;
    private string $lastName;
    private ?string $phone = null;
    private ?\DateTimeImmutable $phoneVerifiedAt = null;
    private ?\DateTimeImmutable $emailVerifiedAt = null;
    private ?\DateTimeImmutable $lastLoginAt = null;
    private \DateTimeImmutable $passwordChangedAt;
    private ?\DateTimeImmutable $deletionRequestedAt = null;
    private ?\DateTimeImmutable $anonymizedAt = null;
    private \DateTimeImmutable $updatedAt;
    private int $version = 1;

    private function __construct(
        private UserId $id,
        Email $email,
        private UserKind $kind,
        private string $passwordHash,
        PersonName $firstName,
        PersonName $lastName,
        private \DateTimeImmutable $createdAt,
    ) {
        $this->email = $email->value;
        $this->emailCanonical = $email->canonical();
        $this->firstName = $firstName->value;
        $this->lastName = $lastName->value;
        $this->passwordChangedAt = $createdAt;
        $this->updatedAt = $createdAt;
    }

    public static function register(UserId $id, Email $email, UserKind $kind, string $passwordHash, PersonName $firstName, PersonName $lastName, \DateTimeImmutable $now): self
    {
        self::assertHash($passwordHash);
        $user = new self($id, $email, $kind, $passwordHash, $firstName, $lastName, $now);
        $user->recordEvent(new UserRegistered($id->value, $kind->value, $now));

        return $user;
    }

    /** A06 — sans effet si déjà vérifié (lien cliqué deux fois). */
    public function verifyEmail(\DateTimeImmutable $now): void
    {
        $this->assertNotAnonymized();
        if (null !== $this->emailVerifiedAt) {
            return;
        }
        $this->emailVerifiedAt = $now;
        if (UserStatus::PendingEmail === $this->status) {
            $this->status = UserStatus::Active;
        }
        $this->touch($now);
        $this->recordEvent(new UserEmailVerified($this->id->value, $now));
    }

    /**
     * Changement d'adresse confirmé par un lien envoyé à la nouvelle adresse (A06) : elle est donc vérifiée.
     * L'ancienne adresse est prévenue (E-06) par l'appelant ; les sessions ouvertes sont fermées (SecurityUser).
     */
    public function changeEmail(Email $email, \DateTimeImmutable $now): void
    {
        $this->assertNotAnonymized();
        if ($email->canonical() === $this->emailCanonical) {
            return;
        }
        $this->email = $email->value;
        $this->emailCanonical = $email->canonical();
        $this->emailVerifiedAt = $now;
        if (UserStatus::PendingEmail === $this->status) {
            $this->status = UserStatus::Active;
        }
        $this->touch($now);
        $this->recordEvent(new UserEmailChanged($this->id->value, $now));
    }

    public function changePassword(string $newHash, \DateTimeImmutable $now): void
    {
        $this->assertNotAnonymized();
        self::assertHash($newHash);
        $this->passwordHash = $newHash;
        $this->passwordChangedAt = $now;
        $this->touch($now);
        $this->recordEvent(new UserPasswordChanged($this->id->value, $now));
    }

    /** Ré-hachage transparent à la connexion (paramètres Argon2id renforcés) : ni événement ni changement de date. */
    public function upgradePasswordHash(string $newHash): void
    {
        $this->assertNotAnonymized();
        self::assertHash($newHash);
        $this->passwordHash = $newHash;
    }

    /** A02 : facultatif à l'inscription, exigé au premier dépôt de demande. Un changement annule la vérification. */
    public function changePhone(?PhoneNumber $phone, \DateTimeImmutable $now): void
    {
        $this->assertNotAnonymized();
        if ($phone?->value === $this->phone) {
            return;
        }
        $this->phone = $phone?->value;
        $this->phoneVerifiedAt = null;
        $this->touch($now);
    }

    public function phone(): ?PhoneNumber
    {
        return null === $this->phone ? null : new PhoneNumber($this->phone);
    }

    public function recordLogin(\DateTimeImmutable $now): void
    {
        if (!$this->status->canLogIn()) {
            throw new IdentityRuleViolation('Connexion impossible pour ce compte.');
        }
        $this->lastLoginAt = $now;
    }

    public function suspend(string $reason, \DateTimeImmutable $now): void
    {
        $this->assertNotAnonymized();
        if (UserStatus::Suspended === $this->status) {
            return;
        }
        $reason = trim($reason);
        if ('' === $reason) {
            throw new IdentityRuleViolation('Motif de suspension obligatoire.');
        }
        $this->status = UserStatus::Suspended;
        $this->touch($now);
        $this->recordEvent(new UserSuspended($this->id->value, mb_substr($reason, 0, 500), $now));
    }

    public function reinstate(\DateTimeImmutable $now): void
    {
        if (UserStatus::Suspended !== $this->status) {
            throw new IdentityRuleViolation('Seul un compte suspendu peut être réactivé.');
        }
        $this->status = $this->settledStatus();
        $this->touch($now);
        $this->recordEvent(new UserReinstated($this->id->value, $now));
    }

    public function requestDeletion(\DateTimeImmutable $now): void
    {
        $this->assertNotAnonymized();
        if (UserStatus::DeletionRequested === $this->status) {
            return;
        }
        if (UserStatus::Suspended === $this->status) {
            // Un compte suspendu conserve ses données le temps de l'instruction (intérêt légitime, CDC §11).
            throw new IdentityRuleViolation('Effacement différé : le compte est suspendu.');
        }
        $this->status = UserStatus::DeletionRequested;
        $this->deletionRequestedAt = $now;
        $this->touch($now);
        $this->recordEvent(new UserDeletionRequested($this->id->value, $now));
    }

    public function cancelDeletion(\DateTimeImmutable $now): void
    {
        if (UserStatus::DeletionRequested !== $this->status) {
            throw new IdentityRuleViolation('Aucune demande d’effacement en cours.');
        }
        $this->status = $this->settledStatus();
        $this->deletionRequestedAt = null;
        $this->touch($now);
    }

    /** Effacement des données personnelles ; l'identifiant reste pour l'intégrité des historiques (devis, factures). */
    public function anonymize(\DateTimeImmutable $now): void
    {
        $this->assertNotAnonymized();
        $this->email = 'compte-'.$this->id->value.'@'.self::ANONYMIZED_DOMAIN;
        $this->emailCanonical = $this->email;
        $this->firstName = 'Utilisateur';
        $this->lastName = 'supprimé';
        $this->phone = null;
        $this->phoneVerifiedAt = null;
        $this->passwordHash = self::UNUSABLE_PASSWORD;
        $this->status = UserStatus::Anonymized;
        $this->anonymizedAt = $now;
        $this->touch($now);
        $this->recordEvent(new UserAnonymized($this->id->value, $now));
    }

    public function id(): UserId
    {
        return $this->id;
    }

    public function email(): Email
    {
        return new Email($this->email);
    }

    public function kind(): UserKind
    {
        return $this->kind;
    }

    public function status(): UserStatus
    {
        return $this->status;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    public function firstName(): string
    {
        return $this->firstName;
    }

    public function lastName(): string
    {
        return $this->lastName;
    }

    public function isEmailVerified(): bool
    {
        return null !== $this->emailVerifiedAt;
    }

    public function lastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function passwordChangedAt(): \DateTimeImmutable
    {
        return $this->passwordChangedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    private function settledStatus(): UserStatus
    {
        return null === $this->emailVerifiedAt ? UserStatus::PendingEmail : UserStatus::Active;
    }

    private function assertNotAnonymized(): void
    {
        if (UserStatus::Anonymized === $this->status) {
            throw new IdentityRuleViolation('Compte anonymisé : opération impossible.');
        }
    }

    private static function assertHash(string $hash): void
    {
        if (!str_starts_with($hash, '$argon2id$')) {
            throw new IdentityRuleViolation('Empreinte de mot de passe attendue (Argon2id).');
        }
    }

    private function touch(\DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
    }
}
