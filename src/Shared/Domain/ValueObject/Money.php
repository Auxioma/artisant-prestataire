<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidValue;

/**
 * Montant en unités mineures (centimes) : jamais de flottant pour l'argent.
 */
final readonly class Money
{
    private const int BASIS_POINTS = 10_000;

    public function __construct(public int $amount, public string $currency = 'EUR')
    {
        if (1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            throw InvalidValue::for('Money', 'devise ISO 4217 attendue');
        }
    }

    public static function zero(string $currency = 'EUR'): self
    {
        return new self(0, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    public function multiply(int $factor): self
    {
        return new self($this->amount * $factor, $this->currency);
    }

    /**
     * Applique un taux exprimé en points de base (2000 = 20 %), arrondi « half up » symétrique.
     */
    public function applyRate(int $basisPoints): self
    {
        $product = $this->amount * $basisPoints;
        $half = intdiv(self::BASIS_POINTS, 2);
        $rounded = $product >= 0
            ? intdiv($product + $half, self::BASIS_POINTS)
            : -intdiv(-$product + $half, self::BASIS_POINTS);

        return new self($rounded, $this->currency);
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amount === $other->amount;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($other->currency !== $this->currency) {
            throw InvalidValue::for('Money', 'devises différentes');
        }
    }
}
