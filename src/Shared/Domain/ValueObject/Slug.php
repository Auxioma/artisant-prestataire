<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidValue;

/**
 * Segment d'URL (CDC §7 : minuscules, tirets, sans accents).
 */
final readonly class Slug implements \Stringable
{
    private const int MAX_LENGTH = 100;
    private const array TRANSLITERATION = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'æ' => 'ae',
        'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'œ' => 'oe', 'ø' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
        '&' => ' et ', '’' => ' ', "'" => ' ',
    ];

    public function __construct(public string $value)
    {
        if (\strlen($value) > self::MAX_LENGTH || 1 !== preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value)) {
            throw InvalidValue::for('Slug', 'minuscules, chiffres et tirets uniquement');
        }
    }

    public static function fromText(string $text): self
    {
        $ascii = strtr(mb_strtolower(trim($text)), self::TRANSLITERATION);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $ascii), '-');
        if ('' === $slug) {
            throw InvalidValue::for('Slug', 'texte sans caractère exploitable');
        }

        return new self(rtrim(substr($slug, 0, self::MAX_LENGTH), '-'));
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
