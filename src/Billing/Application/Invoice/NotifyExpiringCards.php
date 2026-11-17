<?php

declare(strict_types=1);

namespace App\Billing\Application\Invoice;

use App\Shared\Application\Bus\Command;

/** Tâche quotidienne : E-46, carte bientôt expirée (PR13). */
final readonly class NotifyExpiringCards implements Command
{
}
