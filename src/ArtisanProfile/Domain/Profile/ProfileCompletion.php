<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Profile;

/**
 * PR08.1 — jauge de complétude : somme des poids (paramètre `pro.completion_weights`, somme = 1) des éléments
 * renseignés ; les photos comptent au prorata de l'objectif (`pro.completion_photos_target`). < `pro.min_completion_featured`
 * ⇒ non éligible aux mises en avant (RG-011).
 */
final readonly class ProfileCompletion
{
    public const string LOGO = 'logo';
    public const string DESCRIPTION = 'description';
    public const string PHOTOS = 'photos';
    public const string HOURS = 'hours';
    public const string ZONE = 'zone';
    public const string SERVICES = 'services';
    public const string LABELS = 'labels';

    /**
     * @param int                  $percent  0 à 100
     * @param array<string, float> $progress avancement de chaque élément (0 à 1)
     * @param array<string, float> $weights
     */
    private function __construct(public int $percent, public array $progress, public array $weights)
    {
    }

    /** @param array<string, float> $weights */
    public static function compute(array $weights, bool $logo, bool $description, int $photos, int $photosTarget, bool $hours, bool $zone, int $services, bool $labels): self
    {
        $progress = [
            self::LOGO => $logo ? 1.0 : 0.0,
            self::DESCRIPTION => $description ? 1.0 : 0.0,
            self::PHOTOS => min(1.0, max(0, $photos) / max(1, $photosTarget)),
            self::HOURS => $hours ? 1.0 : 0.0,
            self::ZONE => $zone ? 1.0 : 0.0,
            self::SERVICES => $services > 0 ? 1.0 : 0.0,
            self::LABELS => $labels ? 1.0 : 0.0,
        ];
        $score = 0.0;
        foreach ($progress as $item => $done) {
            $score += ($weights[$item] ?? 0.0) * $done;
        }

        return new self((int) round(100 * min(1.0, $score)), $progress, $weights);
    }

    /**
     * Éléments à compléter, du plus au moins pondéré.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        $missing = array_keys(array_filter($this->progress, static fn (float $p): bool => $p < 1.0));
        usort($missing, fn (string $a, string $b): int => ($this->weights[$b] ?? 0.0) <=> ($this->weights[$a] ?? 0.0));

        return $missing;
    }

    public function isDone(string $item): bool
    {
        return ($this->progress[$item] ?? 0.0) >= 1.0;
    }
}
