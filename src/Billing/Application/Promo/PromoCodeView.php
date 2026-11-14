<?php

declare(strict_types=1);

namespace App\Billing\Application\Promo;

use App\Billing\Domain\Promo\PromoTerms;

/** BO08.3 — code promo, utilisations et état (actif, épuisé, expiré, désactivé). */
final readonly class PromoCodeView
{
    /** @param list<string> $planNames */
    public function __construct(
        public string $id,
        public PromoTerms $terms,
        public bool $disabled,
        public int $redemptions,
        public array $planNames,
        public string $stripePromotionCodeId,
        public \DateTimeImmutable $now,
    ) {
    }

    public function state(): string
    {
        return match (true) {
            $this->disabled => 'disabled',
            null !== $this->terms->validUntil && $this->terms->validUntil <= $this->now => 'expired',
            null !== $this->terms->maxRedemptions && $this->redemptions >= $this->terms->maxRedemptions => 'exhausted',
            default => 'active',
        };
    }

    public function stateLabel(): string
    {
        return ['disabled' => 'Désactivé', 'expired' => 'Expiré', 'exhausted' => 'Épuisé', 'active' => 'Actif'][$this->state()];
    }
}
