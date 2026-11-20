<?php

declare(strict_types=1);

namespace App\Review\Application\EventHandler;

use App\Review\Application\Port\ReviewDirectory;
use App\Review\Application\Port\ReviewMailer;
use App\Review\Domain\Review\Event\ReviewChanged;
use App\Shared\Application\Bus\EventHandler;

/** E-18 au client sur chaque décision ; E-37 à l'artisan quand un avis est publié ; E-38 sur la décision prise sur sa réponse. */
final readonly class NotifyReviewChange implements EventHandler
{
    private const array CLIENT_DECISIONS = ['published', 'rejected', 'hidden', 'changes_requested'];

    public function __construct(private ReviewDirectory $directory, private ReviewMailer $mailer)
    {
    }

    public function __invoke(ReviewChanged $event): void
    {
        $p = $this->directory->parties($event->reviewId);
        if (null === $p) {
            return;
        }
        if (\in_array($event->change, self::CLIENT_DECISIONS, true) && null !== $p['client_email']) {
            $this->mailer->reviewDecision($p['client_email'], $p['client_first_name'], $p['client_user_id'], $event->change, $p['artisan_name'], $event->reason);
        }
        if ('published' === $event->change && null !== $p['artisan_email']) {
            $this->mailer->newReview($p['artisan_email'], $p['artisan_first_name'], $p['artisan_user_id'], $p['rating'], $p['request_number']);
        }
        if (\in_array($event->change, ['reply_published', 'reply_rejected'], true)) {
            $this->mailer->replyDecision($p['artisan_user_id'], 'reply_published' === $event->change, $event->reason);
        }
    }
}
