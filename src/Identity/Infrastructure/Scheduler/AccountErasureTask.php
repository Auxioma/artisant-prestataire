<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Scheduler;

use App\Identity\Application\Privacy\EraseDueAccounts;
use App\Identity\Application\Privacy\PurgeIdentityRetention;
use App\Shared\Application\Bus\CommandBus;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/** Chaque nuit : effacement des comptes dont le délai de rétractation est écoulé (RG-090), puis rétention (§11). */
#[AsCronTask('10 5 * * *', timezone: 'Europe/Paris')]
final readonly class AccountErasureTask
{
    public function __construct(private CommandBus $commands)
    {
    }

    public function __invoke(): void
    {
        $this->commands->dispatch(new EraseDueAccounts());
        $this->commands->dispatch(new PurgeIdentityRetention());
    }
}
