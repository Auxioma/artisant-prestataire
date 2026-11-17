<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

use App\Catalog\Domain\Exception\CatalogRuleViolation;

/** Libellé affiché d'une catégorie ou d'un métier (2 à 80 caractères, espaces normalisés). */
final readonly class Label implements \Stringable
{
    private const int MIN = 2;
    private const int MAX = 80;

    public string $value;

    public function __construct(string $value)
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        $length = mb_strlen($value);
        if ($length < self::MIN || $length > self::MAX) {
            throw new CatalogRuleViolation(\sprintf('Le libellé doit contenir entre %d et %d caractères.', self::MIN, self::MAX));
        }
        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
