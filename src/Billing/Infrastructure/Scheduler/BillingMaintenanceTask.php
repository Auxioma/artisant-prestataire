<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Scheduler;

use App\Billing\Application\Invoice\NotifyExpiringCards;
use App\Billing\Application\Subscription\EnforceBillingGrace;
use App\Shared\Application\Bus\CommandBus;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/** Chaque heure : fin de la période de grâce (suspension, E-45), annulation des impayés anciens (§4.3), E-46 (cartes). */
#[AsCronTask('35 * * * *', timezone: 'Europe/Paris')]
final readonly class BillingMaintenanceTask
{
    public function __construct(private CommandBus $commands)
    {
    }

    public function __invoke(): void
    {
        $this->commands->dispatch(new EnforceBillingGrace());
        $this->commands->dispatch(new NotifyExpiringCards());
    }
}
