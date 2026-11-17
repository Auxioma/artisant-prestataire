<?php

declare(strict_types=1);

namespace App\Search\Domain\Ranking;

/**
 * RG-020 — pertinence = somme pondérée (poids `search.weights`, somme = 1) de critères ramenés entre 0 et 1 :
 * métier (principal 1 ; exercé en complément 0,8 ; recherche sans métier 1), distance (1 au centre, 0 à la limite du
 * rayon), note bayésienne / 5, réactivité (taux de réponse ; inconnu = neutre), complétude du profil, disponibilité.
 */
final class RelevanceScore
{
    public const float SECONDARY_JOB = 0.8;
    public const float UNKNOWN_RESPONSIVENESS = 0.5;
    private const float MAX_RATING = 5.0;

    /** @param array<string, float> $weights */
    public static function of(array $weights, ?bool $mainJob, float $distanceKm, int $radiusKm, float $bayesRating, ?float $responseRate, int $completion, bool $available): float
    {
        $criteria = [
            'job' => null === $mainJob ? 1.0 : ($mainJob ? 1.0 : self::SECONDARY_JOB),
            'distance' => $radiusKm > 0 ? max(0.0, 1.0 - $distanceKm / $radiusKm) : 1.0,
            'rating' => max(0.0, min(1.0, $bayesRating / self::MAX_RATING)),
            'responsiveness' => null === $responseRate ? self::UNKNOWN_RESPONSIVENESS : max(0.0, min(1.0, $responseRate)),
            'completion' => max(0, min(100, $completion)) / 100,
            'availability' => $available ? 1.0 : 0.0,
        ];
        $score = 0.0;
        foreach ($criteria as $criterion => $value) {
            $score += ($weights[$criterion] ?? 0.0) * $value;
        }

        return round($score, 6);
    }
}
