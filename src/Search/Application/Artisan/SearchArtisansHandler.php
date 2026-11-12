<?php

declare(strict_types=1);

namespace App\Search\Application\Artisan;

use App\Search\Domain\Ranking\BayesianRating;
use App\Search\Domain\Ranking\RelevanceScore;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;

/**
 * Recherche P02 : sélection RG-021 en base (PostGIS), classement en PHP par la formule unique du domaine (RG-020,
 * note bayésienne RG-012), tri demandé, pagination `search.page_size`. Le classement exposé (« Comment sont classés les
 * professionnels ? ») décrit exactement ce calcul.
 */
final readonly class SearchArtisansHandler implements QueryHandler
{
    /** Aucune note publiée sur la plateforme : la note bayésienne vaut 0 pour tous (critère neutre). */
    private const float NO_RATINGS_MEAN = 0.0;

    public function __construct(private ArtisanSearchIndex $index, private SettingsProvider $settings)
    {
    }

    public function __invoke(SearchArtisans $query): ArtisanSearchResult
    {
        $radius = $query->radiusKm ?? $this->settings->int(SettingKey::SearchDefaultRadiusKm);
        $perPage = $this->settings->int(SettingKey::SearchPageSize);
        $weights = $this->settings->weights(SettingKey::SearchWeights);
        $mean = $this->settings->nullableDecimal(SettingKey::RankingBayesM) ?? $this->index->globalRatingMean() ?? self::NO_RATINGS_MEAN;
        $c = $this->settings->int(SettingKey::RankingBayesC);

        $hits = [];
        foreach ($this->index->candidates($query->communeInsee, $radius, $query->jobId, $query->labels) as $candidate) {
            $bayes = BayesianRating::of($candidate->ratingAverage, $candidate->reviewsCount, $mean, $c);
            if (null !== $query->minRating && (null === $candidate->ratingAverage || $candidate->ratingAverage < $query->minRating)) {
                continue;
            }
            $hits[] = new ArtisanHit(
                $candidate,
                RelevanceScore::of($weights, null === $query->jobId ? null : $candidate->mainJobMatches, $candidate->distanceKm, $radius, $bayes, $candidate->responseRate, $candidate->completion, $candidate->available),
                round($bayes, 2),
            );
        }
        usort($hits, match ($query->sort) {
            SearchArtisans::SORT_DISTANCE => static fn (ArtisanHit $a, ArtisanHit $b): int => [$a->artisan->distanceKm, -$a->bayesRating] <=> [$b->artisan->distanceKm, -$b->bayesRating],
            SearchArtisans::SORT_RATING => static fn (ArtisanHit $a, ArtisanHit $b): int => [-$a->bayesRating, $a->artisan->distanceKm] <=> [-$b->bayesRating, $b->artisan->distanceKm],
            SearchArtisans::SORT_REVIEWS => static fn (ArtisanHit $a, ArtisanHit $b): int => [-$a->artisan->reviewsCount, -$a->bayesRating] <=> [-$b->artisan->reviewsCount, -$b->bayesRating],
            default => static fn (ArtisanHit $a, ArtisanHit $b): int => [-$a->score, $a->artisan->distanceKm, $a->artisan->profileId] <=> [-$b->score, $b->artisan->distanceKm, $b->artisan->profileId],
        });
        $page = max(1, $query->page);

        return new ArtisanSearchResult(\array_slice($hits, ($page - 1) * $perPage, $perPage), \count($hits), $page, $perPage, $radius);
    }
}
