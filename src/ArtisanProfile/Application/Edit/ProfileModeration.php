<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Edit;

use App\ArtisanProfile\Application\Port\ProfileReviews;
use App\ArtisanProfile\Domain\Profile\ArtisanRuleViolation;
use App\Shared\Application\Moderation\ContactSignals;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Text\ContactDetector;

/**
 * RG-031 — modération des contenus du profil public : refus automatique des coordonnées (les clients passent par
 * TrouveMoi, RG-030) et des termes interdits, puis échantillon `moderation.profile_sample_rate` % pour relecture humaine.
 */
final readonly class ProfileModeration
{
    private const int PERCENT = 100;
    private const int FIRST = 1;
    private const array CONTACT_LABELS = [ContactDetector::EMAIL => 'une adresse e-mail', ContactDetector::PHONE => 'un numéro de téléphone', ContactDetector::LINK => 'un lien ou un site web'];

    public function __construct(private ProfileReviews $reviews, private SettingsProvider $settings, private IdGenerator $ids, private ContactSignals $contacts)
    {
    }

    /** @param list<string> $texts */
    public function check(array $texts): void
    {
        $text = implode("\n", $texts);
        $found = ContactDetector::find($text);
        if ([] !== $found) {
            throw new ArtisanRuleViolation(\sprintf('Votre profil ne peut pas contenir %s : les clients vous contactent via TrouveMoi.', implode(' ni ', array_map(static fn (string $t): string => self::CONTACT_LABELS[$t], $found))));
        }
        $keywords = $this->contacts->keywords($text);
        if ([] !== $keywords) {
            throw new ArtisanRuleViolation(\sprintf('Votre profil ne peut pas inviter à vous contacter en dehors de TrouveMoi (« %s ») : les clients vous contactent via la plateforme.', $keywords[0]));
        }
        $lower = mb_strtolower($text);
        foreach ($this->settings->stringList(SettingKey::ModerationForbiddenTerms) as $term) {
            if ('' !== trim($term) && str_contains($lower, mb_strtolower(trim($term)))) {
                throw new ArtisanRuleViolation('Votre texte contient un terme non autorisé sur TrouveMoi. Merci de le reformuler.');
            }
        }
    }

    /** @param array<string, mixed> $content */
    public function sample(string $profileId, string $section, array $content, \DateTimeImmutable $now): void
    {
        if (random_int(self::FIRST, self::PERCENT) <= $this->settings->int(SettingKey::ModerationProfileSampleRate)) {
            $this->reviews->record($this->ids->next(), $profileId, $section, $content, $now);
        }
    }
}
