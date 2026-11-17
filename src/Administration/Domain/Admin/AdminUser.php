<?php

declare(strict_types=1);

namespace App\Administration\Domain\Admin;

use App\Administration\Domain\Admin\Event\AdminActivated;
use App\Administration\Domain\Admin\Event\AdminDisabled;
use App\Administration\Domain\Admin\Event\AdminEnabled;
use App\Administration\Domain\Admin\Event\AdminInvited;
use App\Administration\Domain\Admin\Event\AdminRoleChanged;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\ValueObject\Email;

/**
 * Compte de l'équipe d'administration (BO14.1, ADR-0013) — distinct des comptes clients et artisans.
 * Créé par invitation ; il ne devient actif qu'avec un mot de passe ET une double authentification TOTP confirmée,
 * de sorte qu'aucun compte actif n'existe sans 2FA. Secrets : empreinte du mot de passe, secret TOTP chiffré (SecretBox),
 * empreintes des codes de secours ; le jeton d'invitation n'est conservé que haché.
 */
final class AdminUser extends AggregateRoot
{
    private const int NAME_MIN = 2;
    private const int NAME_MAX = 80;

    private AdminStatus $status = AdminStatus::Invited;
    private ?string $passwordHash = null;
    private ?string $totpSecret = null;
    /** @var list<string> */
    private array $backupCodes = [];
    private ?string $invitationTokenHash = null;
    private ?\DateTimeImmutable $invitationExpiresAt = null;
    private ?\DateTimeImmutable $activatedAt = null;
    private ?\DateTimeImmutable $lastLoginAt = null;
    private \DateTimeImmutable $updatedAt;
    private int $version = 1;

    private function __construct(
        private string $id,
        private string $email,
        private string $name,
        private AdminRole $role,
        private ?string $invitedBy,
        private \DateTimeImmutable $createdAt,
    ) {
        $this->updatedAt = $createdAt;
    }

    public static function invite(string $id, Email $email, string $name, AdminRole $role, string $invitationTokenHash, \DateTimeImmutable $expiresAt, ?string $invitedBy, \DateTimeImmutable $now): self
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if (mb_strlen($name) < self::NAME_MIN || mb_strlen($name) > self::NAME_MAX || 1 === preg_match('/[\p{C}<>]/u', $name)) {
            throw new AdminRuleViolation(\sprintf('Nom de %d à %d caractères attendu.', self::NAME_MIN, self::NAME_MAX));
        }
        $admin = new self($id, $email->canonical(), $name, $role, $invitedBy, $now);
        $admin->invitationTokenHash = $invitationTokenHash;
        $admin->invitationExpiresAt = $expiresAt;
        $admin->recordEvent(new AdminInvited($id, $invitedBy, $now));

        return $admin;
    }

    /** Nouveau lien d'invitation (l'ancien devient inutilisable). */
    public function renewInvitation(string $invitationTokenHash, \DateTimeImmutable $expiresAt, ?string $by, \DateTimeImmutable $now): void
    {
        if (AdminStatus::Invited !== $this->status) {
            throw new AdminRuleViolation('Ce compte a déjà accepté son invitation.');
        }
        $this->invitationTokenHash = $invitationTokenHash;
        $this->invitationExpiresAt = $expiresAt;
        $this->updatedAt = $now;
        $this->recordEvent(new AdminInvited($this->id, $by, $now));
    }

    public function isInvitationValid(string $tokenHash, \DateTimeImmutable $now): bool
    {
        return AdminStatus::Invited === $this->status
            && null !== $this->invitationTokenHash && hash_equals($this->invitationTokenHash, $tokenHash)
            && null !== $this->invitationExpiresAt && $this->invitationExpiresAt > $now;
    }

    /**
     * Invitation acceptée : mot de passe choisi, secret TOTP confirmé par un code valide (vérifié par le cas d'usage),
     * codes de secours générés. Le jeton est consommé.
     *
     * @param list<string> $backupCodeHashes
     */
    public function activate(string $tokenHash, string $passwordHash, string $sealedTotpSecret, array $backupCodeHashes, \DateTimeImmutable $now): void
    {
        if (!$this->isInvitationValid($tokenHash, $now)) {
            throw new AdminRuleViolation('Ce lien d’invitation n’est plus valide. Demandez-en un nouveau à un super-admin.');
        }
        if ([] === $backupCodeHashes) {
            throw new AdminRuleViolation('Codes de secours manquants.');
        }
        $this->passwordHash = $passwordHash;
        $this->totpSecret = $sealedTotpSecret;
        $this->backupCodes = array_values(array_unique($backupCodeHashes));
        $this->invitationTokenHash = null;
        $this->invitationExpiresAt = null;
        $this->status = AdminStatus::Active;
        $this->activatedAt = $now;
        $this->updatedAt = $now;
        $this->recordEvent(new AdminActivated($this->id, $now));
    }

    public function changeRole(AdminRole $role, string $by, \DateTimeImmutable $now): void
    {
        if ($by === $this->id) {
            throw new AdminRuleViolation('Vous ne pouvez pas modifier votre propre rôle.');
        }
        if ($role === $this->role) {
            return;
        }
        $from = $this->role;
        $this->role = $role;
        $this->updatedAt = $now;
        $this->recordEvent(new AdminRoleChanged($this->id, $from->value, $role->value, $by, $now));
    }

    public function disable(string $by, \DateTimeImmutable $now): void
    {
        if ($by === $this->id) {
            throw new AdminRuleViolation('Vous ne pouvez pas désactiver votre propre compte.');
        }
        if (AdminStatus::Disabled === $this->status) {
            return;
        }
        $this->status = AdminStatus::Disabled;
        $this->invitationTokenHash = null;
        $this->updatedAt = $now;
        $this->recordEvent(new AdminDisabled($this->id, $by, $now));
    }

    public function enable(string $by, \DateTimeImmutable $now): void
    {
        if (AdminStatus::Disabled !== $this->status) {
            return;
        }
        if (null === $this->totpSecret) {
            throw new AdminRuleViolation('Ce compte n’a jamais été activé : renvoyez une invitation.');
        }
        $this->status = AdminStatus::Active;
        $this->updatedAt = $now;
        $this->recordEvent(new AdminEnabled($this->id, $by, $now));
    }

    /** Code de secours à usage unique : vrai s'il était valide (et il est alors consommé). */
    public function consumeBackupCode(string $codeHash, \DateTimeImmutable $now): bool
    {
        foreach ($this->backupCodes as $i => $hash) {
            if (hash_equals($hash, $codeHash)) {
                $remaining = $this->backupCodes;
                unset($remaining[$i]);
                $this->backupCodes = array_values($remaining);
                $this->updatedAt = $now;

                return true;
            }
        }

        return false;
    }

    public function recordLogin(\DateTimeImmutable $now): void
    {
        $this->lastLoginAt = $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function email(): Email
    {
        return new Email($this->email);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function role(): AdminRole
    {
        return $this->role;
    }

    public function status(): AdminStatus
    {
        return $this->status;
    }

    public function passwordHash(): ?string
    {
        return $this->passwordHash;
    }

    public function sealedTotpSecret(): ?string
    {
        return $this->totpSecret;
    }

    /** @return list<string> empreintes des codes de secours restants */
    public function backupCodeHashesForCheck(): array
    {
        return $this->backupCodes;
    }

    public function remainingBackupCodes(): int
    {
        return \count($this->backupCodes);
    }

    public function lastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function invitationExpiresAt(): ?\DateTimeImmutable
    {
        return $this->invitationExpiresAt;
    }

    /** Peut se connecter : actif, mot de passe et 2FA configurés. */
    public function canSignIn(): bool
    {
        return AdminStatus::Active === $this->status && null !== $this->passwordHash && null !== $this->totpSecret;
    }
}
