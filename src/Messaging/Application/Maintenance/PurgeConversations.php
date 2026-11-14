<?php

declare(strict_types=1);

namespace App\Messaging\Application\Maintenance;

use App\Shared\Application\Bus\Command;

/** Tâche quotidienne : purge des conversations inactives depuis `messages.retention_years` (C04, §11). */
final readonly class PurgeConversations implements Command
{
}
