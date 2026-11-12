<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use App\Search\Domain\Ranking\BayesianRating;
use App\Search\Domain\Ranking\RelevanceScore;
use PHPUnit\Framework\TestCase;

/** RG-012 / RG-024 (note bayésienne) et RG-020 (pertinence pondérée). */
final class RankingTest extends TestCase
{
    private const array WEIGHTS = ['job' => 0.30, 'distance' => 0.25, 'rating' => 0.20, 'responsiveness' => 0.10, 'completion' => 0.10, 'availability' => 0.05];

    public function testOneFiveStarReviewDoesNotBeatManyGoodOnes(): void
    {
        $single = BayesianRating::of(5.0, 1, 4.2, 10);
        $many = BayesianRating::of(4.8, 120, 4.2, 10);

        self::assertEqualsWithDelta(4.27, $single, 0.01);
        self::assertEqualsWithDelta(4.75, $many, 0.01);
        self::assertGreaterThan($single, $many, 'exemple du CDC : 120 avis à 4,8 devant 1 avis à 5/5');
        self::assertSame(4.2, BayesianRating::of(null, 0, 4.2, 10), 'sans avis : moyenne globale');
    }

    public function testRelevanceIsTheWeightedSumOfNormalizedCriteria(): void
    {
        $best = RelevanceScore::of(self::WEIGHTS, true, 0.0, 30, 5.0, 1.0, 100, true);
        self::assertSame(1.0, $best);

        // Métier secondaire, à mi-rayon, note 4, réactivité inconnue, profil à 50 %, pas de disponibilité.
        $score = RelevanceScore::of(self::WEIGHTS, false, 15.0, 30, 4.0, null, 50, false);
        self::assertEqualsWithDelta(0.30 * 0.8 + 0.25 * 0.5 + 0.20 * 0.8 + 0.10 * 0.5 + 0.10 * 0.5, $score, 0.000001);
    }

    public function testCloserArtisanWinsAllElseEqual(): void
    {
        self::assertGreaterThan(
            RelevanceScore::of(self::WEIGHTS, true, 20.0, 30, 4.2, null, 60, false),
            RelevanceScore::of(self::WEIGHTS, true, 2.0, 30, 4.2, null, 60, false),
        );
    }
}
