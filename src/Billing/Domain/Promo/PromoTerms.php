<?php

declare(strict_types=1);

namespace App\Billing\Domain\Promo;

/**
 * §4.3 — conditions d'un code promo : remise en pourcentage (points de base) ou en montant (centimes HT), durée (une
 * fois, N mois, toujours), forfaits éligibles (aucun = tous), date de fin, utilisations maximales globales et par compte,
 * réservé ou non aux nouveaux abonnés. Les bornes correspondent à celles de Stripe (Coupon / Promotion Code).
 */
final readonly class PromoTerms
{
    public const string PERCENT = 'percent';
    public const string AMOUNT = 'amount';
    public const array DURATIONS = ['once', 'repeating', 'forever'];
    private const int MAX_BASIS_POINTS = 10000;
    private const int MAX_MONTHS = 36;
    private const string CODE_PATTERN = '/^[A-Z0-9_-]{3,30}$/';

    /** @param list<string> $planIds */
    public function __construct(
        public string $code,
        public string $discountType,
        public ?int $percentBp,
        public ?int $amountCents,
        public string $duration,
        public ?int $durationMonths,
        public array $planIds,
        public ?\DateTimeImmutable $validUntil,
        public ?int $maxRedemptions,
        public ?int $maxPerAccount,
        public bool $newSubscribersOnly,
    ) {
        if (1 !== preg_match(self::CODE_PATTERN, $code)) {
            throw new PromoRuleViolation('Code invalide : 3 à 30 caractères, lettres majuscules, chiffres, « - » ou « _ ».');
        }
        if (self::PERCENT === $discountType ? (null === $percentBp || $percentBp < 1 || $percentBp > self::MAX_BASIS_POINTS || null !== $amountCents) : (self::AMOUNT !== $discountType || null === $amountCents || $amountCents < 1 || null !== $percentBp)) {
            throw new PromoRuleViolation('Indiquez une remise : un pourcentage entre 0,01 et 100, ou un montant positif.');
        }
        if (!\in_array($duration, self::DURATIONS, true) || (('repeating' === $duration) !== (null !== $durationMonths)) || (null !== $durationMonths && ($durationMonths < 1 || $durationMonths > self::MAX_MONTHS))) {
            throw new PromoRuleViolation('Durée invalide : une fois, toujours, ou de 1 à 36 mois.');
        }
        if ((null !== $maxRedemptions && $maxRedemptions < 1) || (null !== $maxPerAccount && $maxPerAccount < 1)) {
            throw new PromoRuleViolation('Les nombres d’utilisations doivent être positifs.');
        }
    }

    /** « −20 % pendant 3 mois », « −10,00 € toujours » (affichage BO08.3). */
    public function label(): string
    {
        $value = self::PERCENT === $this->discountType
            ? '−'.rtrim(rtrim(number_format(($this->percentBp ?? 0) / 100, 2, ',', ''), '0'), ',').' %'
            : '−'.number_format(($this->amountCents ?? 0) / 100, 2, ',', ' ').' €';

        return $value;
    }

    public function durationLabel(): string
    {
        return match ($this->duration) {
            'once' => '1 fois',
            'repeating' => $this->durationMonths.' mois',
            default => 'Toujours',
        };
    }
}
