<?php

declare(strict_types=1);

namespace App\ArtisanProfile\UI\Console;

use App\ArtisanProfile\Application\Port\CoverageProjection;
use App\Shared\Application\Transaction\TransactionManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Reconstruit métiers exercés et communes couvertes de tous les artisans (à lancer après `app:geo:import`). */
#[AsCommand(name: 'app:artisan:rebuild-coverage', description: 'Reconstruit artisan_job et service_area_commune')]
final class RebuildCoverageCommand extends Command
{
    public function __construct(private readonly CoverageProjection $coverage, private readonly TransactionManager $transactions)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->transactions->run(fn (): int => $this->coverage->rebuildAll());
        new SymfonyStyle($input, $output)->success(\sprintf('%d profil(s) artisan reconstruit(s).', \is_int($count) ? $count : 0));

        return Command::SUCCESS;
    }
}
