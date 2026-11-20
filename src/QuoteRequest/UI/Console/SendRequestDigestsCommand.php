<?php

declare(strict_types=1);

namespace App\QuoteRequest\UI\Console;

use App\QuoteRequest\Application\Dispatch\SendRequestDigests;
use App\Shared\Application\Bus\CommandBus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Envoi manuel des digests E-30 (identique au Scheduler, sans double envoi). */
#[AsCommand(name: 'app:requests:digest', description: 'Envoie les digests des nouvelles demandes aux artisans (E-30 regroupé)')]
final class SendRequestDigestsCommand extends Command
{
    public function __construct(private readonly CommandBus $commands)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('quotidien', null, InputOption::VALUE_NONE, 'Inclure les artisans en fréquence « quotidien » (passage du matin)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->commands->dispatch(new SendRequestDigests((bool) $input->getOption('quotidien')));
        new SymfonyStyle($input, $output)->success('Digests envoyés (voir le journal d’envoi).');

        return Command::SUCCESS;
    }
}
