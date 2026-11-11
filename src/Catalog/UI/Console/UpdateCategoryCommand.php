<?php

declare(strict_types=1);

namespace App\Catalog\UI\Console;

use App\Catalog\Application\Command\UpdateCategory;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Domain\Exception\DomainException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:catalog:category', description: 'Renomme, réordonne, active/désactive ou met à la une une catégorie')]
final class UpdateCategoryCommand extends Command
{
    public function __construct(private readonly CommandBus $commands)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::REQUIRED, 'Slug actuel de la catégorie')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Nouveau libellé')
            ->addOption('new-slug', null, InputOption::VALUE_REQUIRED, 'Nouveau slug (crée la redirection 301)')
            ->addOption('icon', null, InputOption::VALUE_REQUIRED, 'Pictogramme (nom d’icône du sprite)')
            ->addOption('position', null, InputOption::VALUE_REQUIRED, 'Position')
            ->addOption('activate', null, InputOption::VALUE_NONE)
            ->addOption('deactivate', null, InputOption::VALUE_NONE)
            ->addOption('feature', null, InputOption::VALUE_NONE, 'Met à la une (accueil P01.5)')
            ->addOption('unfeature', null, InputOption::VALUE_NONE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $slug = $input->getArgument('slug');
        if (!\is_string($slug)) {
            throw new \InvalidArgumentException('Slug attendu.');
        }
        $opt = static fn (string $name): ?string => \is_string($v = $input->getOption($name)) ? $v : null;
        $flag = static fn (string $on, string $off): ?bool => $input->getOption($on) ? true : ($input->getOption($off) ? false : null);
        $position = $opt('position');
        try {
            $this->commands->dispatch(new UpdateCategory(
                slug: $slug,
                name: $opt('name'),
                newSlug: $opt('new-slug'),
                icon: $opt('icon'),
                active: $flag('activate', 'deactivate'),
                featured: $flag('feature', 'unfeature'),
                position: null !== $position ? (int) $position : null,
            ));
        } catch (DomainException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success(\sprintf('Catégorie « %s » mise à jour.', $slug));

        return Command::SUCCESS;
    }
}
