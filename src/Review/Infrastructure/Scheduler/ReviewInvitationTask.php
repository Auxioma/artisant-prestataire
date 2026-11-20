<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Scheduler;

use App\Review\Application\Review\SendReviewInvitations;
use App\Shared\Application\Bus\CommandBus;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/** Chaque jour à 10 h : invitations et relances E-16. */
#[AsCronTask('0 10 * * *', timezone: 'Europe/Paris')]
final readonly class ReviewInvitationTask
{
    public function __construct(private CommandBus $commands)
    {
    }

    public function __invoke(): void
    {
        $this->commands->dispatch(new SendReviewInvitations());
    }
}
