<?php

declare(strict_types=1);

namespace App\Quote\Domain\Quote;

use App\Shared\Domain\ValueObject\Money;

/** PR04.2 — acompte demandé : pourcentage du TTC (points de base) ou montant fixe (centimes), au plus le TTC. */
final readonly class Deposit
{
    private function __construct(public ?int $percentBp, public ?int $amount)
    {
    }

    public static function percent(int $basisPoints): self
    {
        if ($basisPoints <= 0 || $basisPoints > QuoteLine::BASIS_POINTS) {
            throw new QuoteRuleViolation('Acompte invalide (de 1 à 100 %).');
        }

        return new self($basisPoints, null);
    }

    public static function amount(int $cents): self
    {
        if ($cents <= 0) {
            throw new QuoteRuleViolation('Montant d’acompte invalide.');
        }

        return new self(null, $cents);
    }

    public function of(Money $totalTtc): Money
    {
        $value = null !== $this->percentBp ? $totalTtc->applyRate($this->percentBp) : new Money($this->amount ?? 0, $totalTtc->currency);
        if ($value->amount > $totalTtc->amount) {
            throw new QuoteRuleViolation('L’acompte ne peut pas dépasser le montant TTC.');
        }

        return $value;
    }
}
