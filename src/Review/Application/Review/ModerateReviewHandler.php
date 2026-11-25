<?php

declare(strict_types=1);

namespace App\Review\Application\Review;

use App\Review\Domain\Review\RejectionReason;
use App\Review\Domain\Review\ReviewId;
use App\Review\Domain\Review\ReviewRepository;
use App\Review\Domain\Review\ReviewRuleViolation;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Application\Moderation\ModerateReview;
use App\Shared\Application\Moderation\ModerationDecisionRejected;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/** BO05.1 — décisions de la modération sur les avis et les réponses (RG-035, RG-036), journalisées. */
final readonly class ModerateReviewHandler implements CommandHandler
{
    public function __construct(private ReviewRepository $reviews, private EventBus $events, private AuditTrail $audit, private IdGenerator $ids, private Clock $clock)
    {
    }

    public function __invoke(ModerateReview $command): void
    {
        $now = $this->clock->now();
        try {
            $review = $this->reviews->findForUpdate(new ReviewId($command->reviewId)) ?? throw new ModerationDecisionRejected('Avis introuvable.');
            $reason = null === $command->reason ? null : RejectionReason::tryFrom($command->reason);
            if (\in_array($command->decision, ['reject', 'hide', 'reply_reject'], true) && null === $reason) {
                throw new ModerationDecisionRejected('Choisissez le motif normalisé.');
            }
            switch ($command->decision) {
                case 'publish': $review->publish($command->adminId, $now);
                    break;
                case 'reject': $review->reject($command->adminId, $reason ?? RejectionReason::OffTopic, $now);
                    break;
                case 'hide': $review->hide($command->adminId, $reason ?? RejectionReason::OffTopic, $now);
                    break;
                case 'changes': $review->requestChanges($command->adminId, (string) $command->note, $now);
                    break;
                case 'reply_publish': $review->moderateReply($command->adminId, null, $now);
                    break;
                case 'reply_reject': $review->moderateReply($command->adminId, $reason, $now);
                    break;
                default: throw new ModerationDecisionRejected('Décision inconnue.');
            }
        } catch (ReviewRuleViolation $e) {
            throw new ModerationDecisionRejected($e->getMessage());
        }
        $this->reviews->save($review);
        $this->events->publish(...$review->releaseEvents());
        $this->audit->record(new AuditRecord($this->ids->next(), $now, 'admin', $command->adminId, 'review.moderated', 'review', $command->reviewId, context: ['decision' => $command->decision, 'reason' => $command->reason]));
    }
}
