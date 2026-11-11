<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Profile;

/** A03.5 — assurance déclarée : assureur, n° de police, date de fin de validité (≥ aujourd'hui). « Vérifiée » après contrôle du justificatif (RG-032). */
final readonly class Insurance
{
    private const int INSURER_MAX = 120;
    private const int POLICY_MAX = 60;

    public string $insurer;
    public string $policyNumber;

    public function __construct(public InsuranceKind $kind, string $insurer, string $policyNumber, public \DateTimeImmutable $validUntil)
    {
        $this->insurer = Text::line($insurer, 2, self::INSURER_MAX) ?? throw new ArtisanRuleViolation(\sprintf('%s : indiquez l’assureur.', $kind->label()));
        $this->policyNumber = Text::line($policyNumber, 1, self::POLICY_MAX) ?? throw new ArtisanRuleViolation(\sprintf('%s : indiquez le numéro de police.', $kind->label()));
    }

    public function isExpiredOn(\DateTimeImmutable $today): bool
    {
        return $this->validUntil->format('Y-m-d') < $today->format('Y-m-d');
    }

    /** @return array{kind: string, insurer: string, policy: string, valid_until: string} */
    public function toArray(): array
    {
        return ['kind' => $this->kind->value, 'insurer' => $this->insurer, 'policy' => $this->policyNumber, 'valid_until' => $this->validUntil->format('Y-m-d')];
    }

    /** @param array{kind: string, insurer: string, policy: string, valid_until: string} $data */
    public static function fromArray(array $data): self
    {
        return new self(InsuranceKind::from($data['kind']), $data['insurer'], $data['policy'], new \DateTimeImmutable($data['valid_until']));
    }
}
