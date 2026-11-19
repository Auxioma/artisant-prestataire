<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Scheduler;

use App\Identity\Application\Privacy\EraseDueAccounts;
use App\Identity\Application\Privacy\NoticeInactiveAccounts;
use App\Identity\Application\Privacy\PurgeIdentityRetention;
use App\Shared\Application\Bus\CommandBus;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * Chaque nuit : relance des comptes inactifs et programmation de l'effacement des relances sans suite (§11), effacement
 * des comptes dont le délai est écoulé (RG-090), puis rétention (§11).
 */
#[AsCronTask('10 5 * * *', timezone: 'Europe/Paris')]
final readonly class AccountErasureTask
{
    public function __construct(private CommandBus $commands)
    {
    }

    public function __invoke(): void
    {
        $this->commands->dispatch(new NoticeInactiveAccounts());
        $this->commands->dispatch(new EraseDueAccounts());
        $this->commands->dispatch(new PurgeIdentityRetention());
    }
}
