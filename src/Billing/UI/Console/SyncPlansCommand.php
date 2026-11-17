<?php

declare(strict_types=1);

namespace App\Billing\UI\Console;

use App\Billing\Application\Payment\SyncPlanWithStripe;
use App\Billing\Application\Port\PaymentGateway;
use App\Billing\Domain\Plan\PlanRepository;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Integration\ExternalServiceUnavailable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Resynchronisation complète du catalogue (première mise en service de la clé, rattrapage après une panne de Stripe). */
#[AsCommand(name: 'app:stripe:sync-plans', description: 'Aligne les produits et prix Stripe sur les forfaits BO08')]
final class SyncPlansCommand extends Command
{
    public function __construct(private readonly PaymentGateway $gateway, private readonly PlanRepository $plans, private readonly CommandBus $commands)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->gateway->isConfigured()) {
            $io->warning('STRIPE_SECRET_KEY absente : rien n’a été envoyé.');

            return Command::FAILURE;
        }
        $failed = 0;
        foreach ($this->plans->all() as $plan) {
            if (!$plan->everPublished()) {
                $io->writeln(\sprintf(' · %s : brouillon jamais publié, ignoré', $plan->terms()->name));
                continue;
            }
            try {
                $this->commands->dispatch(new SyncPlanWithStripe($plan->id()->value));
                $io->writeln(\sprintf(' ✓ %s', $plan->terms()->name));
            } catch (ExternalServiceUnavailable $e) {
                ++$failed;
                $io->writeln(\sprintf(' ✗ %s : %s', $plan->terms()->name, $e->getMessage()));
            }
        }
        $mode = $this->gateway->mode();
        0 === $failed ? $io->success('Catalogue Stripe à jour (mode '.$mode.').') : $io->error($failed.' forfait(s) non synchronisé(s).');

        return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
    }
}
