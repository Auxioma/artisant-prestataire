<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence;

/**
 * Conversion vérifiée des valeurs scalaires renvoyées par DBAL (typées `mixed`) :
 * une valeur inattendue est une erreur d'infrastructure explicite, jamais un cast silencieux.
 */
final class DbalValue
{
    public static function string(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException(\sprintf('Chaîne attendue depuis la base, %s reçu.', get_debug_type($value)));
        }

        return $value;
    }

    public static function nullableString(mixed $value): ?string
    {
        return null === $value || false === $value ? null : self::string($value);
    }

    public static function int(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && 1 === preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }

        throw new \UnexpectedValueException(\sprintf('Entier attendu depuis la base, %s reçu.', get_debug_type($value)));
    }

    /** Agrégat numérique (avg, sum, numeric) : null si aucune ligne. */
    public static function nullableFloat(mixed $value): ?float
    {
        if (null === $value) {
            return null;
        }
        if (\is_int($value) || \is_float($value) || (\is_string($value) && is_numeric($value))) {
            return (float) $value;
        }

        throw new \UnexpectedValueException(\sprintf('Nombre attendu depuis la base, %s reçu.', get_debug_type($value)));
    }

    public static function json(mixed $value): mixed
    {
        return json_decode(self::string($value), true, 16, \JSON_THROW_ON_ERROR);
    }
}
