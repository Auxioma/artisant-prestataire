<?php

declare(strict_types=1);

namespace App\Billing\Application\Subscription;

use App\Shared\Application\Bus\Command;

/** Tâche horaire : fin de la période de grâce (suspension, E-45) et annulation des impayés anciens (§4.3). */
final readonly class EnforceBillingGrace implements Command
{
}
