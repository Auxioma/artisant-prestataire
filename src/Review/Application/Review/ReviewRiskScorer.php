<?php

declare(strict_types=1);

namespace App\Review\Application\Review;

use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Text\ContactDetector;

/**
 * RG-035 — score de risque d'un avis ou d'une réponse (0–100) : mots interdits (BO05.3), coordonnées ou liens,
 * texte crié ou répétitif. Au-delà de `moderation.review_risk_threshold`, le texte passe en modération même en mode
 * a posteriori.
 */
final readonly class ReviewRiskScorer
{
    private const int FORBIDDEN_TERM = 50;
    private const int CONTACT = 60;
    private const int SHOUTING = 15;
    private const int REPEATED = 15;
    private const float SHOUTING_RATIO = 0.6;
    private const int MAX = 100;

    public function __construct(private SettingsProvider $settings)
    {
    }

    public function score(string $text): int
    {
        $score = 0;
        $lower = mb_strtolower($text);
        foreach ($this->settings->stringList(SettingKey::ModerationForbiddenTerms) as $term) {
            if ('' !== trim($term) && str_contains($lower, mb_strtolower(trim($term)))) {
                $score += self::FORBIDDEN_TERM;
                break;
            }
        }
        if ([] !== ContactDetector::mask($text)[1]) {
            $score += self::CONTACT;
        }
        $letters = preg_replace('/[^\p{L}]/u', '', $text) ?? '';
        if (mb_strlen($letters) > 0 && mb_strlen(preg_replace('/[^\p{Lu}]/u', '', $letters) ?? '') / mb_strlen($letters) > self::SHOUTING_RATIO) {
            $score += self::SHOUTING;
        }
        if (1 === preg_match('/(.)\1{5,}/u', $text)) {
            $score += self::REPEATED;
        }

        return min(self::MAX, $score);
    }

    /** Modération a priori, ou score au-delà du seuil. */
    public function needsReview(int $score): bool
    {
        return 'a_priori' === $this->settings->choice(SettingKey::ReviewsModerationMode) || $score >= $this->settings->int(SettingKey::ModerationReviewRiskThreshold);
    }
}
