<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Profile;

/**
 * PR08.2 / PR08.3 — présentation publique : slogan, description (mise en forme limitée : gras, listes), points forts,
 * langues, années d'expérience, clients acceptés.
 */
final readonly class PublicPresentation
{
    public const int SLOGAN_MAX = 120;
    public const int DESCRIPTION_MAX = 3000;
    public const int STRENGTHS_MAX = 5;
    public const int STRENGTH_MAX = 80;
    public const int YEARS_MAX = 80;
    public const array LANGUAGES = ['fr' => 'Français', 'en' => 'Anglais', 'es' => 'Espagnol', 'de' => 'Allemand', 'it' => 'Italien', 'pt' => 'Portugais', 'ar' => 'Arabe', 'nl' => 'Néerlandais', 'pl' => 'Polonais', 'ro' => 'Roumain', 'tr' => 'Turc'];
    public const array CLIENTS = ['particuliers' => 'Particuliers', 'professionnels' => 'Professionnels', 'collectivites' => 'Collectivités', 'syndics' => 'Syndics et bailleurs'];

    /**
     * @param list<string> $strengths
     * @param list<string> $languages
     * @param list<string> $clients
     */
    public function __construct(
        public ?string $slogan,
        public ?string $description,
        public array $strengths,
        public array $languages,
        public ?int $yearsExperience,
        public array $clients,
    ) {
        if (null !== $slogan && mb_strlen($slogan) > self::SLOGAN_MAX) {
            throw new ArtisanRuleViolation(\sprintf('Slogan : %d caractères au maximum.', self::SLOGAN_MAX));
        }
        if (null !== $description && mb_strlen($description) > self::DESCRIPTION_MAX) {
            throw new ArtisanRuleViolation(\sprintf('Description : %d caractères au maximum.', self::DESCRIPTION_MAX));
        }
        if (\count($strengths) > self::STRENGTHS_MAX || [] !== array_filter($strengths, static fn (string $s): bool => mb_strlen($s) > self::STRENGTH_MAX)) {
            throw new ArtisanRuleViolation(\sprintf('Points forts : %d au maximum, %d caractères chacun.', self::STRENGTHS_MAX, self::STRENGTH_MAX));
        }
        if ([] !== array_diff($languages, array_keys(self::LANGUAGES)) || [] !== array_diff($clients, array_keys(self::CLIENTS))) {
            throw new ArtisanRuleViolation('Langue ou type de clients inconnu.');
        }
        if (null !== $yearsExperience && ($yearsExperience < 0 || $yearsExperience > self::YEARS_MAX)) {
            throw new ArtisanRuleViolation('Années d’expérience invalides.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['slogan' => $this->slogan, 'description' => $this->description, 'strengths' => $this->strengths, 'languages' => $this->languages, 'years' => $this->yearsExperience, 'clients' => $this->clients];
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        $strings = static fn (mixed $v): array => \is_array($v) ? array_values(array_filter($v, \is_string(...))) : [];

        return new self(
            \is_string($data['slogan'] ?? null) ? $data['slogan'] : null,
            \is_string($data['description'] ?? null) ? $data['description'] : null,
            $strings($data['strengths'] ?? []),
            $strings($data['languages'] ?? []),
            \is_int($data['years'] ?? null) ? $data['years'] : null,
            $strings($data['clients'] ?? []),
        );
    }
}
