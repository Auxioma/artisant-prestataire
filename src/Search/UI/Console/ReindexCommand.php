<?php

declare(strict_types=1);

namespace App\Search\UI\Console;

use App\Search\Application\Port\SearchUnavailable;
use App\Search\Application\SearchIndexer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Reconstruit les index d'autocomplétion depuis PostgreSQL (après import ou restauration). Sans interruption de service. */
#[AsCommand(name: 'app:search:reindex', description: 'Reconstruit les index d’autocomplétion (métiers, communes)')]
final class ReindexCommand extends Command
{
    public function __construct(private readonly SearchIndexer $indexer)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $counts = $this->indexer->reindexAll();
        } catch (SearchUnavailable $e) {
            $io->error('Moteur de recherche indisponible : '.$e->getMessage());

            return Command::FAILURE;
        }
        $io->success(\sprintf('%d métier(s) et %d commune(s) indexé(s).', $counts['jobs'], $counts['places']));

        return Command::SUCCESS;
    }
}
