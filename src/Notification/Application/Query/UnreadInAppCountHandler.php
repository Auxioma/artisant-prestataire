<?php

declare(strict_types=1);

namespace App\Notification\Application\Query;

use App\Notification\Application\Port\InAppNotifications;
use App\Shared\Application\Bus\QueryHandler;

final readonly class UnreadInAppCountHandler implements QueryHandler
{
    public function __construct(private InAppNotifications $notifications)
    {
    }

    public function __invoke(UnreadInAppCount $query): int
    {
        return 1 === preg_match('/^[0-9a-f-]{36}$/', $query->userId) ? $this->notifications->unreadCount($query->userId) : 0;
    }
}
