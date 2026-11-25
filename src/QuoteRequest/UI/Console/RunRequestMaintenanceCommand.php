<?php

declare(strict_types=1);

namespace App\QuoteRequest\UI\Console;

use App\QuoteRequest\Application\Maintenance\RunRequestMaintenance;
use App\Shared\Application\Bus\CommandBus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Exécution manuelle des tâches horaires des demandes (identique au Scheduler, idempotente). */
#[AsCommand(name: 'app:requests:maintenance', description: 'Expirations RG-064, prévenances E-19, relances E-19c et purge des brouillons')]
final class RunRequestMaintenanceCommand extends Command
{
    public function __construct(private readonly CommandBus $commands)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach (RunRequestMaintenance::TASKS as $task) {
            $this->commands->dispatch(new RunRequestMaintenance($task));
        }
        new SymfonyStyle($input, $output)->success('Tâches des demandes exécutées (voir le journal d’envoi et le journal d’audit).');

        return Command::SUCCESS;
    }
}
