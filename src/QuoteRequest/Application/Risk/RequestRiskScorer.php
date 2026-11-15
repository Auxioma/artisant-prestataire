<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Risk;

use App\QuoteRequest\Application\Port\RequestRisk;
use App\QuoteRequest\Application\Port\RequestRiskAssessor;
use App\QuoteRequest\Application\Port\RequestRiskInput;
use App\QuoteRequest\Application\Port\RequestRiskSignals;
use App\Shared\Application\Moderation\ContactSignals;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Text\ContactDetector;

/**
 * RG-063 — score anti-spam d'une demande : somme des points des signaux relevés (mots-clés interdits, liens,
 * coordonnées, texte identique à une autre demande, rafale par compte ou par IP), plafonnée à 100. Points, seuil et
 * fenêtres sont des paramètres (BO05.3). Au-delà du seuil, la demande passe par la file de modération (BO04.1).
 */
final readonly class RequestRiskScorer implements RequestRiskAssessor
{
    private const int MAX_SCORE = 100;

    public function __construct(private RequestRiskSignals $history, private SettingsProvider $settings, private ContactSignals $contacts)
    {
    }

    public function assess(RequestRiskInput $input): RequestRisk
    {
        $texts = [$input->description, ...$input->otherTexts];
        foreach ($input->answers as $answer) {
            $texts = [...$texts, ...(\is_array($answer) ? $answer : [$answer])];
        }
        $text = implode("\n", $texts);
        $found = $this->contacts->find($text);

        $signals = [];
        if ($this->hasForbiddenTerm($text)) {
            $signals[] = RequestRiskSignal::ForbiddenTerm;
        }
        if (\in_array(ContactDetector::LINK, $found, true)) {
            $signals[] = RequestRiskSignal::Link;
        }
        if (\in_array(ContactDetector::EMAIL, $found, true) || \in_array(ContactDetector::PHONE, $found, true) || \in_array(ContactSignals::KEYWORD, $found, true)) {
            $signals[] = RequestRiskSignal::Contact;
        }
        $similarSince = $input->at->modify(\sprintf('-%d days', $this->settings->int(SettingKey::ModerationRequestSimilarityDays)));
        if ($this->history->hasSameDescriptionSince($input->description, $similarSince)) {
            $signals[] = RequestRiskSignal::DuplicateText;
        }
        $day = $input->at->modify('-1 day');
        $limit = $this->settings->int(SettingKey::ModerationRequestVelocityPerDay);
        if ($this->history->countByClientSince($input->clientId, $day) >= $limit || (null !== $input->ipHash && $this->history->countByIpSince($input->ipHash, $day) >= $limit)) {
            $signals[] = RequestRiskSignal::Velocity;
        }

        $score = 0;
        foreach ($signals as $signal) {
            $score += $this->settings->int(match ($signal) {
                RequestRiskSignal::ForbiddenTerm => SettingKey::ModerationRequestPointsForbiddenTerm,
                RequestRiskSignal::Link => SettingKey::ModerationRequestPointsLink,
                RequestRiskSignal::Contact => SettingKey::ModerationRequestPointsContact,
                RequestRiskSignal::DuplicateText => SettingKey::ModerationRequestPointsDuplicateText,
                RequestRiskSignal::Velocity => SettingKey::ModerationRequestPointsVelocity,
            });
        }

        return new RequestRisk(min(self::MAX_SCORE, $score), array_map(static fn (RequestRiskSignal $s): string => $s->value, $signals));
    }

    private function hasForbiddenTerm(string $text): bool
    {
        $haystack = mb_strtolower($text);
        foreach ($this->settings->stringList(SettingKey::ModerationForbiddenTerms) as $term) {
            $term = mb_strtolower(trim($term));
            if ('' !== $term && 1 === preg_match('/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{N}])/u', $haystack)) {
                return true;
            }
        }

        return false;
    }
}
