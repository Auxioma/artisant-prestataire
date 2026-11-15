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
    private ?string $avatarFileId = null;
    private ?int $searchRadiusKm = null;
    /** @var list<string> */
    private array $interests = [];
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
        $profile->companyName = self::validCompanyName($companyName);
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

    /** C07.1 : code postal (localisation par défaut des demandes) et rayon de recherche préféré. */
    public function relocate(string $postalCode, ?int $searchRadiusKm, \DateTimeImmutable $now): void
    {
        if (null !== $searchRadiusKm && $searchRadiusKm < 1) {
            throw new IdentityRuleViolation('Rayon de recherche invalide.');
        }
        $this->postalCode = self::validPostalCode($postalCode);
        $this->searchRadiusKm = $searchRadiusKm;
        $this->updatedAt = $now;
    }

    /** @param list<ClientInterest> $interests domaines d'intérêt (facultatif) */
    public function changeInterests(array $interests, \DateTimeImmutable $now): void
    {
        $values = array_values(array_unique(array_map(static fn (ClientInterest $i): string => $i->value, $interests)));
        sort($values);
        $this->interests = $values;
        $this->updatedAt = $now;
    }

    /** Remplace la photo ; renvoie l'ancien fichier à détruire. */
    public function changeAvatar(?string $fileId, \DateTimeImmutable $now): ?string
    {
        $previous = $this->avatarFileId;
        $this->avatarFileId = $fileId;
        $this->updatedAt = $now;

        return $previous;
    }

    /** C07 « Passer en compte Professionnel » (raison sociale obligatoire, SIRET facultatif) ou retour en particulier. */
    public function becomeBusiness(string $companyName, ?Siret $siret, bool $siretVerified, \DateTimeImmutable $now): void
    {
        $this->type = ClientType::Business;
        $this->companyName = self::validCompanyName($companyName);
        $this->siret = $siret?->value;
        $this->siretVerified = null !== $siret && $siretVerified;
        $this->updatedAt = $now;
    }

    public function becomeIndividual(\DateTimeImmutable $now): void
    {
        $this->type = ClientType::Individual;
        $this->companyName = null;
        $this->siret = null;
        $this->siretVerified = false;
        $this->updatedAt = $now;
    }

    /** RG-090 : effacement — ne restent que le code postal (statistiques) et les dates ; renvoie la photo à détruire. */
    public function erase(\DateTimeImmutable $now): ?string
    {
        $avatar = $this->avatarFileId;
        $this->type = ClientType::Individual;
        $this->companyName = null;
        $this->siret = null;
        $this->siretVerified = false;
        $this->avatarFileId = null;
        $this->searchRadiusKm = null;
        $this->interests = [];
        $this->newsletterOptInAt = null;
        $this->updatedAt = $now;

        return $avatar;
    }

    public function avatarFileId(): ?string
    {
        return $this->avatarFileId;
    }

    public function searchRadiusKm(): ?int
    {
        return $this->searchRadiusKm;
    }

    /** @return list<ClientInterest> */
    public function interests(): array
    {
        return array_values(array_filter(array_map(ClientInterest::tryFrom(...), $this->interests)));
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

    private static function validCompanyName(string $companyName): string
    {
        $companyName = trim((string) preg_replace('/\s+/u', ' ', $companyName));
        if (mb_strlen($companyName) < self::COMPANY_MIN || mb_strlen($companyName) > self::COMPANY_MAX || 1 === preg_match('/[\p{C}<>]/u', $companyName)) {
            throw new IdentityRuleViolation('Raison sociale invalide.');
        }

        return $companyName;
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
