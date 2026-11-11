<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Profile;

use App\Shared\Domain\ValueObject\Siret;

/**
 * A03.2 — entreprise déclarée. `verified` : données confirmées par le répertoire Sirene ; sinon saisie manuelle
 * (API indisponible) et vérification différée lors de la validation du dossier.
 */
final readonly class CompanyDetails
{
    private const int NAME_MAX = 120;

    public string $companyName;
    public ?string $tradeName;

    public function __construct(
        public Siret $siret,
        string $companyName,
        ?string $tradeName,
        public ?string $legalForm,
        public ?string $ape,
        public ?string $vatNumber,
        public VatStatus $vatStatus,
        public ?string $hqStreet,
        public ?string $hqPostalCode,
        public ?string $hqCity,
        public ?string $hqInsee,
        public ?\DateTimeImmutable $createdOn,
        public bool $verified,
    ) {
        $this->companyName = self::name($companyName) ?? throw new ArtisanRuleViolation('Raison sociale obligatoire.');
        $this->tradeName = self::name($tradeName);
        if (null !== $hqPostalCode && 1 !== preg_match('/^\d{5}$/', $hqPostalCode)) {
            throw new ArtisanRuleViolation('Code postal du siège invalide.');
        }
        if (VatStatus::Liable === $vatStatus && null === $vatNumber) {
            throw new ArtisanRuleViolation('Numéro de TVA intracommunautaire obligatoire pour une entreprise assujettie.');
        }
    }

    /** TVA intracommunautaire française : FR + clé + SIREN, clé = (12 + 3 × (SIREN mod 97)) mod 97. */
    public static function frenchVatNumber(Siret $siret): string
    {
        $siren = $siret->siren();

        return \sprintf('FR%02d%s', (12 + 3 * ((int) $siren % 97)) % 97, $siren);
    }

    private static function name(?string $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));
        if ('' === $value) {
            return null;
        }
        if (mb_strlen($value) > self::NAME_MAX || 1 === preg_match('/[\p{C}<>]/u', $value)) {
            throw new ArtisanRuleViolation('Nom d’entreprise invalide.');
        }

        return $value;
    }
}
