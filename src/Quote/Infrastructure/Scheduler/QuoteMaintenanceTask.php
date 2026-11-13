<?php

declare(strict_types=1);

namespace App\Quote\Infrastructure\Scheduler;

use App\Quote\Application\Expiry\ExpireQuotes;
use App\Shared\Application\Bus\CommandBus;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/** Chaque heure : devis dont la validité est dépassée → « expiré » (RG-121), place libérée. */
#[AsCronTask('20 * * * *', timezone: 'Europe/Paris')]
final readonly class QuoteMaintenanceTask
{
    public function __construct(private CommandBus $commands)
    {
    }

    public function __invoke(): void
    {
        $this->commands->dispatch(new ExpireQuotes());
    }
}
