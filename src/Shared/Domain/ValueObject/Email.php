<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidValue;

final readonly class Email implements \Stringable
{
    private const int MAX_LENGTH = 254;

    public string $value;

    public function __construct(string $value)
    {
        $value = trim($value);
        if ('' === $value || \strlen($value) > self::MAX_LENGTH || false === filter_var($value, \FILTER_VALIDATE_EMAIL)) {
            throw InvalidValue::for('Email', 'format incorrect');
        }
        $at = strrpos($value, '@');
        \assert(false !== $at);
        $this->value = substr($value, 0, $at).'@'.strtolower(substr($value, $at + 1));
    }

    /** Forme canonique utilisée pour l'unicité des comptes. */
    public function canonical(): string
    {
        return mb_strtolower($this->value);
    }

    public function domain(): string
    {
        return substr($this->value, (int) strrpos($this->value, '@') + 1);
    }

    public function equals(self $other): bool
    {
        return $this->canonical() === $other->canonical();
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
