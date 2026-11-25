<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidValue;

/**
 * Numéro de téléphone normalisé E.164. Les numéros nationaux français (0X XX XX XX XX) sont convertis en +33.
 */
final readonly class PhoneNumber implements \Stringable
{
    public string $value;

    public function __construct(string $value)
    {
        $digits = preg_replace('/[\s.\-()]/', '', $value) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        } elseif (1 === preg_match('/^0[1-9]\d{8}$/', $digits)) {
            $digits = '+33'.substr($digits, 1);
        }
        if (1 !== preg_match('/^\+[1-9]\d{7,14}$/', $digits)
            || (str_starts_with($digits, '+33') && 1 !== preg_match('/^\+33[1-9]\d{8}$/', $digits))) {
            throw InvalidValue::for('Téléphone', 'format incorrect');
        }
        $this->value = $digits;
    }

    /** Forme masquée pour l'affichage partiel et les journaux (ex. +336******12). */
    public function masked(): string
    {
        return substr($this->value, 0, 4).str_repeat('*', \strlen($this->value) - 6).substr($this->value, -2);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
