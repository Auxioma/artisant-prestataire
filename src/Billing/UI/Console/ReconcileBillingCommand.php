<?php

declare(strict_types=1);

namespace App\Billing\UI\Console;

use App\Billing\Application\Reconciliation\ReconcileBilling;
use App\Billing\Application\Reconciliation\ReconciliationStore;
use App\Shared\Application\Bus\CommandBus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Lance le rapprochement à la demande (après un incident, une mise en service de clé Stripe). */
#[AsCommand(name: 'app:billing:reconcile', description: 'Rapprochement Stripe ↔ base et contrôle des soldes du registre')]
final class ReconcileBillingCommand extends Command
{
    public function __construct(private readonly CommandBus $commands, private readonly ReconciliationStore $store)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->commands->dispatch(new ReconcileBilling());
        $run = $this->store->lastRun();
        $io = new SymfonyStyle($input, $output);
        $open = $this->store->openIssues();
        foreach ($open as $issue) {
            $io->writeln(\sprintf(' ✗ %s — %s : %s', $issue->label(), $issue->reference, $issue->details));
        }
        $summary = \sprintf('%d élément(s) vérifié(s)%s, %d écart(s) ouvert(s).', $run->checked ?? 0, ($run->stripeChecked ?? false) ? '' : ' (Stripe non interrogé)', \count($open));
        [] === $open ? $io->success($summary) : $io->warning($summary);

        return [] === $open ? Command::SUCCESS : Command::FAILURE;
    }
}
