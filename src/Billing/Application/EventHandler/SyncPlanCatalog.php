<?php

declare(strict_types=1);

namespace App\Billing\Application\EventHandler;

use App\Billing\Application\Payment\SyncPlanWithStripe;
use App\Billing\Domain\Plan\Event\PlanChanged;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\EventHandler;

/** Après un changement de forfait (outbox, asynchrone) : resynchronisation Stripe hors de la transaction de BO08. */
final readonly class SyncPlanCatalog implements EventHandler
{
    public function __construct(private CommandBus $commands)
    {
    }

    public function __invoke(PlanChanged $event): void
    {
        $this->commands->dispatch(new SyncPlanWithStripe($event->planId));
    }
}
