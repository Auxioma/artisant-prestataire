<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidValue;

/**
 * SIRET (14 chiffres) avec contrôle de clé de Luhn (A03.2, RG-041).
 * Exception documentée INSEE : établissements de La Poste (SIREN 356000000), somme des chiffres multiple de 5.
 */
final readonly class Siret implements \Stringable
{
    private const string LA_POSTE_SIREN = '356000000';
    /** Siège de La Poste : seul établissement du SIREN 356000000 soumis à la clé de Luhn standard. */
    private const string LA_POSTE_HEAD_OFFICE = '35600000000048';

    public string $value;

    public function __construct(string $value)
    {
        $normalized = preg_replace('/[\s.\-]/', '', $value) ?? '';
        if (1 !== preg_match('/^\d{14}$/', $normalized)) {
            throw InvalidValue::for('SIRET', '14 chiffres attendus');
        }
        if (!self::hasValidChecksum($normalized)) {
            throw InvalidValue::for('SIRET', 'clé de contrôle incorrecte');
        }
        $this->value = $normalized;
    }

    public function siren(): string
    {
        return substr($this->value, 0, 9);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function hasValidChecksum(string $digits): bool
    {
        if (str_starts_with($digits, self::LA_POSTE_SIREN) && self::LA_POSTE_HEAD_OFFICE !== $digits) {
            return 0 === array_sum(array_map(intval(...), str_split($digits))) % 5;
        }

        $sum = 0;
        foreach (str_split(strrev($digits)) as $index => $char) {
            $digit = (int) $char;
            if (1 === $index % 2) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }

        return 0 === $sum % 10;
    }
}
