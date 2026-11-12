<?php

declare(strict_types=1);

namespace App\ArtisanProfile\UI\Console;

use App\ArtisanProfile\Application\Maintenance\HideUninsuredProfiles;
use App\ArtisanProfile\Application\Maintenance\SendDocumentExpiryAlerts;
use App\ArtisanProfile\Application\Maintenance\SendSignupReminders;
use App\Shared\Application\Bus\CommandBus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Exécution manuelle des tâches quotidiennes (identique au Scheduler, idempotente). */
#[AsCommand(name: 'app:artisan:maintenance', description: 'Alertes d’échéance, masquage RG-130 et relances des dossiers incomplets')]
final class RunMaintenanceCommand extends Command
{
    public function __construct(private readonly CommandBus $commands)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ([new SendDocumentExpiryAlerts(), new HideUninsuredProfiles(), new SendSignupReminders()] as $command) {
            $this->commands->dispatch($command);
        }
        new SymfonyStyle($input, $output)->success('Tâches quotidiennes des artisans exécutées (voir le journal d’envoi et le journal d’audit).');

        return Command::SUCCESS;
    }
}
