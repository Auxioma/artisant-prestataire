<?php

declare(strict_types=1);

namespace App\Identity\Domain\Client;

use App\Identity\Domain\User\IdentityRuleViolation;
use App\Identity\Domain\User\UserId;
use App\Shared\Domain\ValueObject\Siret;

/**
 * Profil client (A02, C07). Entreprise cliente : raison sociale obligatoire, SIRET facultatif (vérifié via Sirene
 * s'il est saisi, A02). Le code postal sert de localisation par défaut des futures demandes.
 */
final class ClientProfile
{
    private const int COMPANY_MIN = 2;
    private const int COMPANY_MAX = 120;

    private ?string $companyName = null;
    private ?string $siret = null;
    private bool $siretVerified = false;
    private ?\DateTimeImmutable $newsletterOptInAt = null;
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        private UserId $userId,
        private ClientType $type,
        private string $postalCode,
        private \DateTimeImmutable $createdAt,
    ) {
        $this->updatedAt = $createdAt;
    }

    public static function individual(UserId $userId, string $postalCode, \DateTimeImmutable $now): self
    {
        return new self($userId, ClientType::Individual, self::validPostalCode($postalCode), $now);
    }

    public static function business(UserId $userId, string $postalCode, string $companyName, ?Siret $siret, bool $siretVerified, \DateTimeImmutable $now): self
    {
        $profile = new self($userId, ClientType::Business, self::validPostalCode($postalCode), $now);
        $companyName = trim((string) preg_replace('/\s+/u', ' ', $companyName));
        if (mb_strlen($companyName) < self::COMPANY_MIN || mb_strlen($companyName) > self::COMPANY_MAX || 1 === preg_match('/[\p{C}<>]/u', $companyName)) {
            throw new IdentityRuleViolation('Raison sociale invalide.');
        }
        $profile->companyName = $companyName;
        $profile->siret = $siret?->value;
        $profile->siretVerified = null !== $siret && $siretVerified;

        return $profile;
    }

    /** Consentement explicite et horodaté (RGPD art. 7) ; retrait possible à tout moment depuis C07 ou l'e-mail. */
    public function subscribeToNewsletter(\DateTimeImmutable $now): void
    {
        $this->newsletterOptInAt ??= $now;
        $this->updatedAt = $now;
    }

    public function unsubscribeFromNewsletter(\DateTimeImmutable $now): void
    {
        $this->newsletterOptInAt = null;
        $this->updatedAt = $now;
    }

    public function userId(): UserId
    {
        return $this->userId;
    }

    public function type(): ClientType
    {
        return $this->type;
    }

    public function companyName(): ?string
    {
        return $this->companyName;
    }

    public function siret(): ?Siret
    {
        return null === $this->siret ? null : new Siret($this->siret);
    }

    public function isSiretVerified(): bool
    {
        return $this->siretVerified;
    }

    public function postalCode(): string
    {
        return $this->postalCode;
    }

    public function isSubscribedToNewsletter(): bool
    {
        return null !== $this->newsletterOptInAt;
    }

    private static function validPostalCode(string $value): string
    {
        $value = trim($value);
        if (1 !== preg_match('/^\d{5}$/', $value)) {
            throw new IdentityRuleViolation('Code postal invalide.');
        }

        return $value;
    }
}
