<?php

declare(strict_types=1);

namespace App\Search\Application\Directory;

use App\Search\Application\Artisan\ArtisanSearchIndex;
use App\Search\Domain\Ranking\BayesianRating;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;

/**
 * RG-024 — éligibilité : au moins `ranking.min_reviews` avis publiés sur la période ; classement par note bayésienne
 * (m = `ranking.bayes_m` ou moyenne de la période, C = `ranking.bayes_c`), puis nombre d'avis.
 */
final readonly class TopRatedArtisansHandler implements QueryHandler
{
    private const int CANDIDATES = 200;
    private const float NO_RATINGS_MEAN = 0.0;

    public function __construct(private ArtisanSearchIndex $index, private SettingsProvider $settings)
    {
    }

    public function __invoke(TopRatedArtisans $query): TopRatedResult
    {
        $min = $this->settings->int(SettingKey::RankingMinReviews);
        $weight = $this->settings->int(SettingKey::RankingBayesC);
        $mean = $this->settings->nullableDecimal(SettingKey::RankingBayesM) ?? $this->index->ratingMeanSince($query->months) ?? self::NO_RATINGS_MEAN;

        $rows = $this->index->topRated($query->jobIds, $query->departmentCode, $query->months, $min, self::CANDIDATES);
        $scored = array_map(static fn (array $r): array => $r + ['bayes' => BayesianRating::of($r['average'], $r['count'], $mean, $weight)], $rows);
        usort($scored, static fn (array $a, array $b): int => [-$a['bayes'], -$a['count']] <=> [-$b['bayes'], -$b['count']]);

        $entries = [];
        foreach (\array_slice($scored, 0, max(1, $query->limit)) as $i => $r) {
            $entries[] = new TopRatedEntry($i + 1, $r['candidate'], round($r['bayes'], 2), round($r['average'], 2), $r['count'], $r['last_review']);
        }

        return new TopRatedResult($entries, round($mean, 2), $weight, $min);
    }
}
