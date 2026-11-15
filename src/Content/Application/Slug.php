<?php

declare(strict_types=1);

namespace App\Content\Application;

/** Adresse lisible : minuscules, chiffres et tirets, accents retirés, longueur bornée (chaîne vide si rien d'exploitable). */
final class Slug
{
    public static function make(string $value, int $max): string
    {
        $ascii = \Normalizer::normalize(mb_strtolower(trim($value)), \Normalizer::FORM_D);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', (string) preg_replace('/\p{Mn}/u', '', \is_string($ascii) ? $ascii : '')), '-');

        return rtrim(mb_substr($slug, 0, $max), '-');
    }
}
