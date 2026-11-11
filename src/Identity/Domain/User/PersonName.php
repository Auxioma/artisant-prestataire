<?php

declare(strict_types=1);

namespace App\Identity\Domain\User;

/** Prénom ou nom (CDC A02) : 2 à 50 caractères, lettres de toutes langues, espaces, apostrophes, traits d'union et points. */
final readonly class PersonName
{
    private const int MIN_LENGTH = 2;
    private const int MAX_LENGTH = 50;

    public string $value;

    public function __construct(string $value)
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        if (mb_strlen($value) < self::MIN_LENGTH || mb_strlen($value) > self::MAX_LENGTH || 1 !== preg_match("/^[\\p{L}\\p{M}][\\p{L}\\p{M} '’.\\-]*$/u", $value)) {
            throw new IdentityRuleViolation('Prénom ou nom invalide.');
        }
        $this->value = $value;
    }
}
