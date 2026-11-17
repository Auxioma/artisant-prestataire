<?php

declare(strict_types=1);

namespace App\Billing\Application\Reconciliation;

use App\Shared\Application\Bus\Command;

/** Tâche quotidienne (03:00) : rapprochement Stripe ↔ base et contrôle des soldes du registre. */
final readonly class ReconcileBilling implements Command
{
}
