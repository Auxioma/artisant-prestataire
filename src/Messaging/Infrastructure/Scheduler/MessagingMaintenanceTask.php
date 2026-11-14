<?php

declare(strict_types=1);

namespace App\Messaging\Infrastructure\Scheduler;

use App\Messaging\Application\Maintenance\PurgeConversations;
use App\Shared\Application\Bus\CommandBus;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/** Chaque nuit à 4 h 10 : purge des conversations inactives (C04, `messages.retention_years`). */
#[AsCronTask('10 4 * * *', timezone: 'Europe/Paris')]
final readonly class MessagingMaintenanceTask
{
    public function __construct(private CommandBus $commands)
    {
    }

    public function __invoke(): void
    {
        $this->commands->dispatch(new PurgeConversations());
    }
}
