<?php

declare(strict_types=1);

namespace App\Search\Domain\Ranking;

/**
 * RG-012 / RG-024 — note bayésienne : (C × m + n × moyenne) / (C + n). Un artisan avec 1 avis à 5/5 ne passe pas devant
 * 120 avis à 4,8. m = `ranking.bayes_m` ou, à défaut, moyenne globale ; C = `ranking.bayes_c`. Sans avis : m.
 */
final class BayesianRating
{
    public static function of(?float $average, int $reviews, float $globalMean, int $weight): float
    {
        if (null === $average || $reviews <= 0) {
            return $globalMean;
        }
        $weight = max(0, $weight);

        return ($weight * $globalMean + $reviews * $average) / ($weight + $reviews);
    }
}
