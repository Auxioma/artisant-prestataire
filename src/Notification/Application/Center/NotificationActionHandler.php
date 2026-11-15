<?php

declare(strict_types=1);

namespace App\Notification\Application\Center;

use App\Notification\Application\Port\InAppNotifications;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Time\Clock;

/** C08 — lecture (une ou toutes) et conservation 90 jours. */
final readonly class NotificationActionHandler implements CommandHandler
{
    public function __construct(private InAppNotifications $notifications, private Clock $clock)
    {
    }

    public function __invoke(NotificationAction|PurgeNotifications $command): void
    {
        if ($command instanceof PurgeNotifications) {
            $this->notifications->purgeBefore($this->clock->now()->modify(PurgeNotifications::RETENTION));

            return;
        }
        $this->notifications->markRead($command->userId, $command->id, $this->clock->now());
    }
}
