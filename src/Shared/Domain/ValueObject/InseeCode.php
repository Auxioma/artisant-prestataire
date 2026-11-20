<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidValue;

/**
 * Code officiel géographique INSEE d'une commune (5 caractères, Corse 2A/2B, outre-mer 97x).
 */
final readonly class InseeCode implements \Stringable
{
    public string $value;

    public function __construct(string $value)
    {
        $value = strtoupper(trim($value));
        if (1 !== preg_match('/^(?:\d{2}|2A|2B)\d{3}$/', $value)) {
            throw InvalidValue::for('Code INSEE', 'format incorrect');
        }
        $this->value = $value;
    }

    public function departmentCode(): string
    {
        return str_starts_with($this->value, '97') ? substr($this->value, 0, 3) : substr($this->value, 0, 2);
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
