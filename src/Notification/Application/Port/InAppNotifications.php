<?php

declare(strict_types=1);

namespace App\Notification\Application\Port;

interface InAppNotifications
{
    public function add(string $id, string $userId, string $code, string $body, ?string $link, \DateTimeImmutable $at): void;

    public function unreadCount(string $userId): int;
}
