<?php

declare(strict_types=1);

namespace App\Quote\Domain\Quote;

use App\Shared\Domain\ValueObject\Money;

/**
 * PR04.2 — totaux : lignes arrondies au centime, remise globale répartie par taux, TVA par taux (arrondie par taux),
 * TTC ; franchise en base (art. 293 B du CGI) : aucune TVA.
 */
final readonly class QuoteTotals
{
    /**
     * @param array<int, array{ht: Money, vat: Money}> $byRate taux (points de base) => base HT nette et TVA
     */
    private function __construct(public Money $grossHt, public Money $discount, public Money $ht, public Money $vat, public Money $ttc, public array $byRate, public ?Money $deposit)
    {
    }

    /** @param list<QuoteLine> $lines */
    public static function of(array $lines, int $globalDiscountBp, bool $vatExempt, ?Deposit $deposit): self
    {
        if ($globalDiscountBp < 0 || $globalDiscountBp > QuoteLine::BASIS_POINTS) {
            throw new QuoteRuleViolation('Remise globale invalide (de 0 à 100 %).');
        }
        $buckets = [];
        foreach ($lines as $line) {
            if ($line->type->isPriced()) {
                $rate = $vatExempt ? VatRate::Zero->value : $line->vatRate->value;
                $buckets[$rate] = ($buckets[$rate] ?? Money::zero())->add($line->totalHt());
            }
        }
        krsort($buckets);
        $grossHt = $discount = $ht = $vat = Money::zero();
        $byRate = [];
        foreach ($buckets as $rate => $base) {
            $cut = $base->applyRate($globalDiscountBp);
            $net = $base->subtract($cut);
            $tax = $net->applyRate($rate);
            $byRate[$rate] = ['ht' => $net, 'vat' => $tax];
            $grossHt = $grossHt->add($base);
            $discount = $discount->add($cut);
            $ht = $ht->add($net);
            $vat = $vat->add($tax);
        }
        $ttc = $ht->add($vat);

        return new self($grossHt, $discount, $ht, $vat, $ttc, $byRate, $deposit?->of($ttc));
    }
}
