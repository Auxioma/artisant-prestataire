<?php

declare(strict_types=1);

namespace App\Shared\Application\Moderation;

use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Text\ContactDetector;

/**
 * BO05.3 « Détection de coordonnées » : motifs intégrés (ContactDetector : e-mail, téléphone, lien) complétés par les
 * expressions de contournement éditables en back-office (`moderation.contact_keywords` : « whatsapp », « mon numéro »…).
 */
final readonly class ContactSignals
{
    public const string KEYWORD = 'keyword';

    public function __construct(private SettingsProvider $settings)
    {
    }

    /** @return list<string> types trouvés (ContactDetector::EMAIL|PHONE|LINK, self::KEYWORD) */
    public function find(string $text): array
    {
        $found = ContactDetector::find($text);
        if ([] !== $this->keywords($text)) {
            $found[] = self::KEYWORD;
        }

        return $found;
    }

    /** @return list<string> expressions de contournement présentes dans le texte */
    public function keywords(string $text): array
    {
        $haystack = ' '.self::normalize($text).' ';
        $found = [];
        foreach ($this->settings->stringList(SettingKey::ModerationContactKeywords) as $keyword) {
            $needle = self::normalize($keyword);
            if ('' !== $needle && 1 === preg_match('/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u', $haystack)) {
                $found[] = trim($keyword);
            }
        }

        return $found;
    }

    private static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $ascii = \Normalizer::normalize($text, \Normalizer::FORM_D);

        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\p{Mn}/u', '', \is_string($ascii) ? $ascii : $text)));
    }
}
