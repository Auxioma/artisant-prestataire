<?php

declare(strict_types=1);

namespace App\Messaging\Application\Conversation;

use App\Shared\Application\Moderation\ContactSignals;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;

/**
 * BO05.3 « Seuils anti-spam » — score de risque d'un message (0-100) : terme interdit, coordonnées ou contournement
 * avant l'acceptation d'un devis, lien, majuscules, répétitions. Au-delà de `moderation.message_risk_threshold`, le
 * message rejoint « Messages signalés » (il reste délivré : la modération décide). Pondérations : P-128.
 */
final readonly class MessageRiskScorer
{
    public const string REASON_PREFIX = 'auto:';
    private const int FORBIDDEN_TERM = 50;
    private const int CONTACT = 30;
    private const int WORKAROUND = 30;
    private const int SHOUTING = 15;
    private const int REPEATED = 15;
    private const float SHOUTING_RATIO = 0.6;
    private const int SHOUTING_MIN_LETTERS = 12;
    private const int MAX = 100;

    public function __construct(private SettingsProvider $settings, private ContactSignals $contacts)
    {
    }

    /** @param bool $beforeAcceptance coordonnées et contournement ne comptent qu'avant l'acceptation d'un devis (RG-080) */
    public function score(string $text, bool $beforeAcceptance): int
    {
        $score = 0;
        $lower = mb_strtolower($text);
        foreach ($this->settings->stringList(SettingKey::ModerationForbiddenTerms) as $term) {
            $term = mb_strtolower(trim($term));
            if ('' !== $term && 1 === preg_match('/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{N}])/u', $lower)) {
                $score += self::FORBIDDEN_TERM;
                break;
            }
        }
        if ($beforeAcceptance) {
            $found = $this->contacts->find($text);
            $score += [] !== array_diff($found, [ContactSignals::KEYWORD]) ? self::CONTACT : 0;
            $score += \in_array(ContactSignals::KEYWORD, $found, true) ? self::WORKAROUND : 0;
        }
        $letters = preg_replace('/[^\p{L}]/u', '', $text) ?? '';
        if (mb_strlen($letters) >= self::SHOUTING_MIN_LETTERS && mb_strlen(preg_replace('/[^\p{Lu}]/u', '', $letters) ?? '') / mb_strlen($letters) > self::SHOUTING_RATIO) {
            $score += self::SHOUTING;
        }
        if (1 === preg_match('/(.)\1{5,}/u', $text)) {
            $score += self::REPEATED;
        }

        return min(self::MAX, $score);
    }

    public function exceedsThreshold(int $score): bool
    {
        return $score > 0 && $score >= $this->settings->int(SettingKey::ModerationMessageRiskThreshold);
    }

    public static function reason(int $score): string
    {
        return self::REASON_PREFIX.$score;
    }

    /** Libellé d'un motif automatique (« Détection automatique (score 80/100) »), null sinon. */
    public static function label(string $reason): ?string
    {
        return str_starts_with($reason, self::REASON_PREFIX) ? \sprintf('Détection automatique (score %s/100)', substr($reason, \strlen(self::REASON_PREFIX))) : null;
    }
}
