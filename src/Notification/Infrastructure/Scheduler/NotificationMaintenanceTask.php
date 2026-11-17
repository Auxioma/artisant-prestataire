<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Scheduler;

use App\Notification\Application\Center\PurgeNotifications;
use App\Shared\Application\Bus\CommandBus;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/** Chaque nuit : purge des notifications in-app de plus de 90 jours (C08.1). */
#[AsCronTask('40 4 * * *', timezone: 'Europe/Paris')]
final readonly class NotificationMaintenanceTask
{
    public function __construct(private CommandBus $commands)
    {
    }

    public function __invoke(): void
    {
        $this->commands->dispatch(new PurgeNotifications());
    }
}
