<?php

declare(strict_types=1);

namespace App\Review\Application\Query;

use App\Review\Application\Port\ReviewDirectory;
use App\Review\Application\Review\ReviewWindow;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Time\Clock;

final readonly class ReviewQueriesHandler implements QueryHandler
{
    public function __construct(private ReviewReader $reader, private ReviewDirectory $directory, private SettingsProvider $settings, private Clock $clock)
    {
    }

    public function __invoke(ClientReviews|ReviewPhoto|ArtisanReviews $query): ClientReviewsView|ArtisanReviewsView|bool|null
    {
        if ($query instanceof ArtisanReviews) {
            return $this->artisan($query);
        }
        if ($query instanceof ReviewPhoto) {
            $review = $this->reader->one($query->reviewId);

            return null !== $review && \in_array($query->fileId, $review->photos, true) && ('published' === $review->status || $query->userId === $review->clientUserId);
        }
        $now = $this->clock->now();
        $days = $this->settings->int(SettingKey::ReviewsWindowDays);
        $toGive = [];
        foreach ($this->reader->reviewable($query->userId) as $r) {
            [$opens, $closes] = ReviewWindow::of($r['accepted_at'], $r['service_at'], $days);
            if ($now <= $closes) {
                $toGive[] = ['request_id' => $r['request_id'], 'request_number' => $r['request_number'], 'request_title' => $r['request_title'], 'artisan_id' => $r['artisan_id'], 'artisan_name' => $r['artisan_name'], 'logo' => $r['logo'], 'opens' => $opens, 'closes' => $closes, 'open' => $now >= $opens];
            }
        }

        return new ClientReviewsView($toGive, $this->reader->ofClient($query->userId), $this->settings->choice(SettingKey::ReviewsModerationMode), $this->settings->int(SettingKey::ReviewsEditDays));
    }

    private function artisan(ArtisanReviews $query): ?ArtisanReviewsView
    {
        $artisanId = $this->directory->artisanOfUser($query->userId);
        if (null === $artisanId) {
            return null;
        }
        $now = $this->clock->now();
        $days = $this->settings->int(SettingKey::ReviewsWindowDays);
        $solicit = [];
        foreach ($this->reader->solicitable($artisanId) as $r) {
            [$opens, $closes] = ReviewWindow::of($r['accepted_at'], $r['service_at'], $days);
            if ($now >= $opens && $now <= $closes) {
                $solicit[] = ['request_id' => $r['request_id'], 'request_title' => $r['request_title'], 'request_number' => $r['request_number'], 'client_name' => $r['client_name'], 'opens' => $opens, 'closes' => $closes, 'reminded' => null !== $r['artisan_reminded_at']];
            }
        }

        return new ArtisanReviewsView($artisanId, $this->reader->stats($artisanId, $now->modify(ArtisanReviews::TREND)), $this->reader->publishedOf($artisanId), $solicit, $this->settings->int(SettingKey::ReviewsEditDays));
    }
}
