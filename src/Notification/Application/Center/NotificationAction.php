<?php

declare(strict_types=1);

namespace App\Notification\Application\Center;

use App\Shared\Application\Bus\Command;

/** C08 — marquer une notification lue (`$id`) ou toutes (`$id` null). */
final readonly class NotificationAction implements Command
{
    public function __construct(public string $userId, public ?string $id = null)
    {
    }
}
