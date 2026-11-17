<?php

declare(strict_types=1);

namespace App\Catalog\UI\Console;

use App\Catalog\Application\Query\CatalogTree;
use App\Shared\Application\Bus\QueryBus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Arborescence du référentiel (vue console de BO06.1, en attendant le back-office — T-209). */
#[AsCommand(name: 'app:catalog:tree', description: 'Affiche l’arborescence catégories → métiers')]
final class CatalogTreeCommand extends Command
{
    public function __construct(private readonly QueryBus $queries)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tree = $this->queries->ask(new CatalogTree());
        if (!\is_array($tree) || [] === $tree) {
            $io->note('Référentiel vide : lancez app:catalog:import.');

            return Command::SUCCESS;
        }
        /** @var list<array{name: string, slug: string, root: bool, active: bool, featured: bool, jobs: list<array{name: string, slug: string, active: bool, decennial: bool, synonyms: list<string>}>}> $tree */
        foreach ($tree as $category) {
            $io->writeln(\sprintf('%s<info>%s</info> (/categories/%s)%s%s', $category['root'] ? '' : '  └ ', $category['name'], $category['slug'], $category['active'] ? '' : ' <comment>[inactive]</comment>', $category['featured'] ? ' ★' : ''));
            foreach ($category['jobs'] as $job) {
                $io->writeln(\sprintf('    • %s (%s)%s%s%s', $job['name'], $job['slug'], $job['decennial'] ? ' [décennale]' : '', [] !== $job['synonyms'] ? ' — synonymes : '.implode(', ', $job['synonyms']) : '', $job['active'] ? '' : ' <comment>[inactif]</comment>'));
            }
        }

        return Command::SUCCESS;
    }
}
