<?php

declare(strict_types=1);

namespace App\Quote\Domain\Quote;

use App\Shared\Domain\ValueObject\Money;

/**
 * PR04.2 — ligne de devis. Quantité en millièmes (2,5 m² = 2 500), prix unitaire HT en centimes, remise et TVA en
 * points de base. Total HT de la ligne arrondi au centime (« arrondi à la ligne »).
 */
final readonly class QuoteLine
{
    public const int QUANTITY_SCALE = 1000;
    public const int BASIS_POINTS = 10_000;
    public const int LABEL_MAX = 200;
    public const int DESCRIPTION_MAX = 2000;
    public const int UNIT_MAX = 20;
    public const string LINE_MESSAGE = 'Chaque ligne doit avoir une désignation et une quantité.';

    public string $label;
    public ?string $description;
    public ?string $unit;

    public function __construct(
        public LineType $type,
        string $label,
        ?string $description = null,
        public int $quantityMilli = 0,
        ?string $unit = null,
        public int $unitPriceHt = 0,
        public int $discountBp = 0,
        public VatRate $vatRate = VatRate::Standard,
    ) {
        $label = trim($label);
        if ('' === $label || mb_strlen($label) > self::LABEL_MAX) {
            throw new QuoteRuleViolation(self::LINE_MESSAGE);
        }
        $description = null === $description ? '' : trim($description);
        if (mb_strlen($description) > self::DESCRIPTION_MAX) {
            throw new QuoteRuleViolation(\sprintf('La description d’une ligne ne doit pas dépasser %d caractères.', self::DESCRIPTION_MAX));
        }
        $unit = null === $unit ? '' : trim($unit);
        if (mb_strlen($unit) > self::UNIT_MAX) {
            throw new QuoteRuleViolation('Unité invalide.');
        }
        if ($type->isPriced()) {
            if ($quantityMilli <= 0) {
                throw new QuoteRuleViolation(self::LINE_MESSAGE);
            }
            if ($unitPriceHt < 0) {
                throw new QuoteRuleViolation('Le prix unitaire ne peut pas être négatif.');
            }
            if ($discountBp < 0 || $discountBp > self::BASIS_POINTS) {
                throw new QuoteRuleViolation('Remise invalide (de 0 à 100 %).');
            }
        }
        $this->label = $label;
        $this->description = '' === $description ? null : $description;
        $this->unit = '' === $unit ? null : $unit;
    }

    /** Total HT de la ligne, remise déduite, arrondi au centime ; zéro pour une section ou un commentaire. */
    public function totalHt(): Money
    {
        if (!$this->type->isPriced()) {
            return Money::zero();
        }
        $gross = self::roundDiv($this->unitPriceHt * $this->quantityMilli, self::QUANTITY_SCALE);

        return new Money($gross - new Money($gross)->applyRate($this->discountBp)->amount);
    }

    private static function roundDiv(int $value, int $divisor): int
    {
        return intdiv($value + intdiv($divisor, 2), $divisor);
    }
}
