<?php

declare(strict_types=1);

namespace App\Notification\Application\Center;

use App\Shared\Application\Bus\Command;

/** C08.1 — conservation 90 jours des notifications in-app. */
final readonly class PurgeNotifications implements Command
{
    public const string RETENTION = '-90 days';
}
