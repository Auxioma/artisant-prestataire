<?php

declare(strict_types=1);

namespace App\Shared\Domain\Identifier;

use App\Shared\Domain\Exception\InvalidValue;

/**
 * Identifiant public d'agrégat : UUID (v7 généré par IdGenerator), jamais séquentiel (anti-énumération, cadrage §5.2).
 */
abstract readonly class AggregateId implements \Stringable
{
    private const string PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public string $value;

    final public function __construct(string $value)
    {
        $value = strtolower(trim($value));
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw InvalidValue::for(static::class, 'UUID attendu');
        }
        $this->value = $value;
    }

    final public function equals(self $other): bool
    {
        return $other instanceof static && $other->value === $this->value;
    }

    final public function __toString(): string
    {
        return $this->value;
    }
}
