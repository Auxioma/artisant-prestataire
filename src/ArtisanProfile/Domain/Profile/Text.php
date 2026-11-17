<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Profile;

/** Normalisation des textes saisis : caractères de contrôle retirés, espaces réduits, longueur bornée. */
final class Text
{
    /** Une ligne : null si vide ou trop courte ; erreur si trop longue. */
    public static function line(?string $value, int $min, int $max): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', (string) $value)));
        if (mb_strlen($value) < $min) {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new ArtisanRuleViolation(\sprintf('%d caractères au maximum.', $max));
        }

        return $value;
    }

    /** Texte court multiligne (description) : sauts de ligne conservés (au plus deux consécutifs), balises refusées. */
    public static function paragraph(?string $value, int $max): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", (string) $value);
        $value = (string) preg_replace('/[^\P{Cc}\n]+|\p{Cf}+/u', '', $value);
        $value = trim((string) preg_replace(["/[ \t]+/u", "/\n{3,}/"], [' ', "\n\n"], $value));
        if (1 === preg_match('/<[a-z!\/?]/i', $value)) {
            throw new ArtisanRuleViolation('La description ne doit pas contenir de balises HTML.');
        }
        if (mb_strlen($value) > $max) {
            throw new ArtisanRuleViolation(\sprintf('La description ne doit pas dépasser %d caractères.', $max));
        }

        return $value;
    }
}
