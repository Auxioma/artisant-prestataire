<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Persistence;

use App\Review\Domain\Review\RejectionReason;
use App\Review\Domain\Review\Review;
use App\Review\Domain\Review\ReviewContent;
use App\Review\Domain\Review\ReviewId;
use App\Review\Domain\Review\ReviewRepository;
use App\Review\Domain\Review\ReviewStatus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalReviewRepository implements ReviewRepository
{
    private const string TS = 'Y-m-d\TH:i:s.uP';

    public function __construct(private Connection $connection)
    {
    }

    public function findForUpdate(ReviewId $id): ?Review
    {
        $r = $this->connection->fetchAssociative('SELECT * FROM review.review WHERE id = :id FOR UPDATE', ['id' => $id->value]);

        return false === $r ? null : self::hydrate($r);
    }

    public function existsForRequest(string $requestId): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM review.review WHERE request_id = :r', ['r' => $requestId]);
    }

    public function save(Review $r): void
    {
        $c = $r->content();
        $f = static fn (?\DateTimeImmutable $d): ?string => $d?->format(self::TS);
        $data = [
            'rating' => $c->rating,
            'sub_ratings' => json_encode((object) $c->subRatings, \JSON_THROW_ON_ERROR),
            'title' => $c->title,
            'body' => $c->text,
            'photos' => json_encode($c->photos, \JSON_THROW_ON_ERROR),
            'service_date' => $c->serviceDate->format('Y-m-d'),
            'recommends' => null === $c->recommends ? null : ($c->recommends ? 'true' : 'false'),
            'status' => $r->status()->value,
            'risk_score' => $r->riskScore(),
            'reason_code' => $r->reason()?->value,
            'moderator_note' => $r->moderatorNote(),
            'moderated_by' => $r->moderatedBy(),
            'moderated_at' => $f($r->moderatedAt()),
            'reply_body' => $r->reply(),
            'reply_status' => $r->replyStatus()?->value,
            'reply_at' => $f($r->repliedAt()),
            'reply_reason' => $r->replyReason()?->value,
            'reported_at' => $f($r->reportedAt()),
            'report_reason' => $r->reportReason(),
            'updated_at' => $f($r->updatedAt()),
        ];
        if (0 === $this->connection->update('review.review', $data, ['id' => $r->id()->value])) {
            $this->connection->insert('review.review', $data + [
                'id' => $r->id()->value,
                'request_id' => $r->requestId(),
                'quote_id' => $r->quoteId(),
                'client_user_id' => $r->clientUserId(),
                'artisan_id' => $r->artisanId(),
                'created_at' => $f($r->createdAt()),
            ]);
        }
    }

    public function delete(ReviewId $id): void
    {
        $this->connection->delete('review.review', ['id' => $id->value]);
    }

    /** @return array<string, int> */
    private static function ratings(mixed $sub): array
    {
        $out = [];
        foreach (\is_array($sub) ? $sub : [] as $k => $v) {
            if (\is_string($k) && \is_int($v)) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $r */
    private static function hydrate(array $r): Review
    {
        $d = static fn (mixed $v): ?\DateTimeImmutable => null === $v ? null : new \DateTimeImmutable(DbalValue::string($v));
        $sub = DbalValue::json($r['sub_ratings']);
        $photos = DbalValue::json($r['photos']);

        return Review::restore(
            new ReviewId(DbalValue::string($r['id'])),
            DbalValue::string($r['request_id']),
            DbalValue::string($r['quote_id']),
            DbalValue::string($r['client_user_id']),
            DbalValue::string($r['artisan_id']),
            new ReviewContent(
                DbalValue::int($r['rating']),
                self::ratings($sub),
                DbalValue::nullableString($r['title']),
                DbalValue::string($r['body']),
                new \DateTimeImmutable(DbalValue::string($r['service_date'])),
                null === $r['recommends'] ? null : true === $r['recommends'],
                \is_array($photos) ? array_values(array_filter($photos, 'is_string')) : [],
            ),
            ReviewStatus::from(DbalValue::string($r['status'])),
            DbalValue::int($r['risk_score']),
            null === $r['reason_code'] ? null : RejectionReason::tryFrom(DbalValue::string($r['reason_code'])),
            DbalValue::nullableString($r['moderator_note']),
            DbalValue::nullableString($r['moderated_by']),
            $d($r['moderated_at']),
            DbalValue::nullableString($r['reply_body']),
            null === $r['reply_status'] ? null : ReviewStatus::from(DbalValue::string($r['reply_status'])),
            $d($r['reply_at']),
            null === $r['reply_reason'] ? null : RejectionReason::tryFrom(DbalValue::string($r['reply_reason'])),
            $d($r['reported_at']),
            DbalValue::nullableString($r['report_reason']),
            new \DateTimeImmutable(DbalValue::string($r['created_at'])),
            new \DateTimeImmutable(DbalValue::string($r['updated_at'])),
        );
    }
}
