<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Scheduler;

use App\Billing\Application\Reconciliation\ReconcileBilling;
use App\Shared\Application\Bus\CommandBus;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/** Chaque nuit à 3 h : rapprochement Stripe ↔ base et contrôle des soldes du registre (ADR-0010, ADR-0006). */
#[AsCronTask('0 3 * * *', timezone: 'Europe/Paris')]
final readonly class BillingReconciliationTask
{
    public function __construct(private CommandBus $commands)
    {
    }

    public function __invoke(): void
    {
        $this->commands->dispatch(new ReconcileBilling());
    }
}
