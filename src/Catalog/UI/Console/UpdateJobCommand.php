<?php

declare(strict_types=1);

namespace App\Catalog\UI\Console;

use App\Catalog\Application\Command\DeactivateJob;
use App\Catalog\Application\Command\UpdateJob;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Domain\Exception\DomainException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Édition d'un métier en ligne de commande (même cas d'usage que BO06.2, journalisé). */
#[AsCommand(name: 'app:catalog:job', description: 'Renomme, déplace, modifie les synonymes ou désactive un métier')]
final class UpdateJobCommand extends Command
{
    public function __construct(private readonly CommandBus $commands)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::REQUIRED, 'Slug actuel du métier')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Nouveau libellé')
            ->addOption('new-slug', null, InputOption::VALUE_REQUIRED, 'Nouveau slug (crée les redirections 301)')
            ->addOption('category', null, InputOption::VALUE_REQUIRED, 'Slug de la catégorie de destination')
            ->addOption('synonym', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Synonymes (remplacent la liste actuelle)')
            ->addOption('deactivate', null, InputOption::VALUE_NONE, 'Désactive le métier')
            ->addOption('replace-with', null, InputOption::VALUE_REQUIRED, 'Métier de remplacement (obligatoire si le métier est utilisé)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $slug = $input->getArgument('slug');
        if (!\is_string($slug)) {
            throw new \InvalidArgumentException('Slug attendu.');
        }
        try {
            if ($input->getOption('deactivate')) {
                $replacement = $input->getOption('replace-with');
                $this->commands->dispatch(new DeactivateJob($slug, \is_string($replacement) ? $replacement : null));
                $io->success(\sprintf('Métier « %s » désactivé.', $slug));

                return Command::SUCCESS;
            }
            $synonyms = $input->getOption('synonym');
            $name = $input->getOption('name');
            $newSlug = $input->getOption('new-slug');
            $category = $input->getOption('category');
            $this->commands->dispatch(new UpdateJob(
                slug: $slug,
                name: \is_string($name) ? $name : null,
                newSlug: \is_string($newSlug) ? $newSlug : null,
                categorySlug: \is_string($category) ? $category : null,
                synonyms: \is_array($synonyms) && [] !== $synonyms ? array_values(array_filter($synonyms, \is_string(...))) : null,
            ));
        } catch (DomainException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success(\sprintf('Métier « %s » mis à jour.', $slug));

        return Command::SUCCESS;
    }
}
