<?php

declare(strict_types=1);

namespace App\Review\Application\Query;

use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Application\Moderation\PendingReviews;
use App\Shared\Application\Moderation\ReviewModerationItem;
use App\Shared\Application\Review\PublicReviewItem;
use App\Shared\Application\Review\PublicReviews;
use App\Shared\Application\Review\PublicReviewsView;
use App\Shared\Domain\Time\Clock;

/** Lectures des avis pour les autres modules : fiche publique P10.9 (Search) et file BO05.1 (Moderation). */
final readonly class ReviewSharedQueriesHandler implements QueryHandler
{
    private const int QUEUE = 100;
    private const int PAGE_MAX = 200;

    public function __construct(private ReviewReader $reader, private Clock $clock)
    {
    }

    /** @return PublicReviewsView|list<ReviewModerationItem> */
    public function __invoke(PublicReviews|PendingReviews $query): PublicReviewsView|array
    {
        if ($query instanceof PendingReviews) {
            return array_map(static fn (array $q): ReviewModerationItem => new ReviewModerationItem(
                $q['view']->id,
                $q['kind'],
                $q['view']->artisanName,
                $q['view']->author(),
                $q['view']->rating,
                $q['view']->title,
                $q['view']->text,
                $q['view']->photos,
                $q['view']->reply,
                $q['view']->reportReason,
                $q['view']->riskScore,
                array_filter(['quote' => $q['quote_number'], 'accepted' => $q['accepted_at']?->format('d/m/Y'), 'service' => $q['service_at']?->format('d/m/Y')], static fn (?string $v): bool => null !== $v && '' !== $v),
                ['reviews' => $q['author_reviews'], 'rejections' => $q['author_rejections']],
                $q['view']->requestId,
                $q['view']->createdAt,
            ), $this->reader->moderationQueue(self::QUEUE));
        }
        $sort = \array_key_exists($query->sort, PublicReviews::SORTS) ? $query->sort : 'recents';
        $filter = \array_key_exists($query->filter, PublicReviews::FILTERS) ? $query->filter : 'toutes';
        $page = $this->reader->publicPage($query->artisanId, $sort, $filter, max(PublicReviews::PAGE, min(self::PAGE_MAX, $query->limit)));
        $stats = $this->reader->stats($query->artisanId, $this->clock->now());

        return new PublicReviewsView(
            $stats['average'],
            $stats['count'],
            $stats['distribution'],
            $stats['criteria'],
            array_map(static fn (ReviewView $r): PublicReviewItem => new PublicReviewItem($r->id, $r->author(), $r->city, $r->serviceDate, $r->rating, $r->title, $r->text, $r->photos, 'published' === $r->replyStatus ? $r->reply : null), $page['items']),
            $page['matching'],
        );
    }
}
