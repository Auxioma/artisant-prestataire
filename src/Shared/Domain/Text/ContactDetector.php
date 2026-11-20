<?php

declare(strict_types=1);

namespace App\Shared\Domain\Text;

/**
 * Détection de coordonnées dans un texte libre (RG-031 profils, RG-080 messagerie) : adresse e-mail, numéro de téléphone
 * (formats français et internationaux, séparateurs et chiffres écrits « zéro six… » exclus), lien ou nom de domaine.
 */
final class ContactDetector
{
    public const string EMAIL = 'email';
    public const string PHONE = 'phone';
    public const string LINK = 'link';

    private const array PATTERNS = [
        self::EMAIL => '/[a-z0-9._%+-]+\s*(@|\(at\)|\[at\]|\sarobase\s)\s*[a-z0-9.-]+\s*(\.|\(dot\)|\sp(oi)?nt\s)\s*[a-z]{2,}/iu',
        self::PHONE => '/(?<!\d)(?:(?:\+|00)\s*\d{2,3}|0)\s*[1-9](?:[\s.\-\/]*\d{2}){4}(?!\d)/u',
        self::LINK => '/\b(?:https?:\/\/|www\.)\S+|\b[a-z0-9-]{2,}\.(?:fr|com|net|org|eu|io|info|biz|pro|be|ch|re)\b/iu',
    ];

    /** RG-080 — mention qui remplace chaque coordonnée détectée (ADR-0014). */
    public const string MASK = '[Coordonnée masquée — partagée après acceptation du devis]';

    /**
     * Remplace chaque coordonnée trouvée par la mention RG-080.
     *
     * @return array{string, list<string>} texte masqué, types de coordonnées trouvés
     */
    public static function mask(string $text): array
    {
        $found = [];
        foreach (self::PATTERNS as $type => $pattern) {
            $masked = preg_replace($pattern, self::MASK, $text, -1, $count);
            if (\is_string($masked) && $count > 0) {
                $text = $masked;
                $found[] = $type;
            }
        }

        return [$text, $found];
    }

    /** @return list<string> types de coordonnées trouvés */
    public static function find(string $text): array
    {
        $found = [];
        foreach (self::PATTERNS as $type => $pattern) {
            if (1 === preg_match($pattern, $text)) {
                $found[] = $type;
            }
        }

        return $found;
    }
}
