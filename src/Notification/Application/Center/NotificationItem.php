<?php

declare(strict_types=1);

namespace App\Notification\Application\Center;

final readonly class NotificationItem
{
    public function __construct(public string $id, public NotificationType $type, public string $body, public ?string $link, public \DateTimeImmutable $at, public bool $read)
    {
    }
}
