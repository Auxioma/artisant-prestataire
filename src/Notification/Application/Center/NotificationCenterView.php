<?php

declare(strict_types=1);

namespace App\Notification\Application\Center;

final readonly class NotificationCenterView
{
    /** @param list<NotificationItem> $items */
    public function __construct(public array $items, public int $total, public int $unread, public int $page, public int $perPage)
    {
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }
}
