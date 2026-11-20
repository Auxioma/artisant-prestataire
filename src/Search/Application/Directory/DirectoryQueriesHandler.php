<?php

declare(strict_types=1);

namespace App\Search\Application\Directory;

use App\Search\Application\Artisan\ArtisanHit;
use App\Search\Application\Artisan\ArtisanSearchIndex;
use App\Search\Domain\Ranking\BayesianRating;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;

/** Comptages, mises en avant et annuaire des pages publiques (P03 à P06). */
final readonly class DirectoryQueriesHandler implements QueryHandler
{
    /** Aucune note publiée : la note bayésienne vaut 0 pour tous (même convention que la recherche, P-50). */
    private const float NO_RATINGS_MEAN = 0.0;
    private const int NEUTRAL_SCORE = 0;

    public function __construct(private ArtisanSearchIndex $index, private SettingsProvider $settings)
    {
    }

    /** @return array<string, int>|int|list<ArtisanHit>|DirectoryResult|list<array<string, mixed>> */
    public function __invoke(ArtisanCountsByJob|CountArtisans|FeaturedArtisans|DirectoryArtisans|SitemapLocalPages|SitemapArtisans $query): array|int|DirectoryResult
    {
        if ($query instanceof SitemapLocalPages) {
            $min = $this->settings->int(SettingKey::SeoMinProsIndex);
            $pages = $this->index->indexableLocalPages($this->settings->int(SettingKey::SearchDefaultRadiusKm), $min);
            foreach ($this->index->countsByDepartment() as $code => $count) {
                if ($count >= $min) {
                    $pages[] = ['city' => null, 'job' => null, 'department' => (string) $code, 'count' => $count];
                }
            }

            return $pages;
        }
        if ($query instanceof SitemapArtisans) {
            return $this->index->publicArtisans();
        }
        if ($query instanceof DirectoryArtisans) {
            $perPage = $this->settings->int(SettingKey::SearchPageSize);
            $page = max(1, $query->page);
            $found = $this->index->directory($query->jobIds, $query->departmentCode, $query->letter, $query->labels, $query->minRating, ($page - 1) * $perPage, $perPage);

            return new DirectoryResult($found['candidates'], $found['total'], $page, $perPage, $this->index->directoryLetters());
        }
        if ($query instanceof CountArtisans) {
            return $this->index->countAmong($query->jobIds, $query->departmentCode);
        }
        if ($query instanceof ArtisanCountsByJob) {
            if (null === $query->communeInsee) {
                return $this->index->countsByJob($query->departmentCode);
            }
            $counts = [];
            foreach ($this->index->candidates($query->communeInsee, $query->radiusKm ?? $this->settings->int(SettingKey::SearchDefaultRadiusKm), [], []) as $candidate) {
                foreach ($candidate->jobIds as $jobId) {
                    $counts[$jobId] = ($counts[$jobId] ?? 0) + 1;
                }
            }

            return $counts;
        }

        $mean = $this->settings->nullableDecimal(SettingKey::RankingBayesM) ?? $this->index->globalRatingMean() ?? self::NO_RATINGS_MEAN;
        $c = $this->settings->int(SettingKey::RankingBayesC);
        $hits = array_map(
            static fn ($candidate): ArtisanHit => new ArtisanHit($candidate, self::NEUTRAL_SCORE, round(BayesianRating::of($candidate->ratingAverage, $candidate->reviewsCount, $mean, $c), 2)),
            $this->index->featured($query->jobIds, $query->departmentCode, $this->settings->int(SettingKey::ProMinCompletionFeatured), $query->limit),
        );
        usort($hits, static fn (ArtisanHit $a, ArtisanHit $b): int => [-$a->bayesRating, -$a->artisan->completion] <=> [-$b->bayesRating, -$b->artisan->completion]);

        return $hits;
    }
}
