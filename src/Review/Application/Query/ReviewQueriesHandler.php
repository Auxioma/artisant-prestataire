<?php

declare(strict_types=1);

namespace App\Review\Application\Query;

use App\Review\Application\Review\ReviewWindow;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Time\Clock;

final readonly class ReviewQueriesHandler implements QueryHandler
{
    public function __construct(private ReviewReader $reader, private SettingsProvider $settings, private Clock $clock)
    {
    }

    public function __invoke(ClientReviews|ReviewPhoto $query): ClientReviewsView|bool
    {
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
}
