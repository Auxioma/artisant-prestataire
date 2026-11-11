<?php

declare(strict_types=1);

namespace App\Catalog\UI\Console;

use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\Command\CreateJob;
use App\Catalog\Domain\Category\CategoryRepository;
use App\Catalog\Domain\Job\JobRepository;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Domain\ValueObject\Slug;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Charge le référentiel initial (config/catalog/referentiel.yaml). Idempotent : n'ajoute que les éléments absents,
 * ne modifie jamais un élément existant (le BO06 fait foi après l'import).
 */
#[AsCommand(name: 'app:catalog:import', description: 'Importe le référentiel initial des catégories et métiers')]
final class ImportCatalogCommand extends Command
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly CategoryRepository $categories,
        private readonly JobRepository $jobs,
        #[Autowire('%kernel.project_dir%/config/catalog/referentiel.yaml')]
        private readonly string $defaultFile,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::OPTIONAL, 'Fichier YAML du référentiel')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche ce qui serait créé sans rien écrire');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = $input->getArgument('file');
        $data = Yaml::parseFile(\is_string($file) ? $file : $this->defaultFile);
        if (!\is_array($data) || !\is_array($data['categories'] ?? null)) {
            $io->error('Fichier invalide : clé « categories » attendue.');

            return Command::FAILURE;
        }
        $defaults = \is_array($data['defaults'] ?? null) ? $data['defaults'] : [];
        $baseDocuments = self::stringList($defaults['required_documents'] ?? []);
        $dryRun = (bool) $input->getOption('dry-run');
        $created = ['categories' => 0, 'jobs' => 0];

        foreach ($data['categories'] as $category) {
            if (!\is_array($category) || !\is_string($category['name'] ?? null) || !\is_string($category['icon'] ?? null)) {
                $io->error('Catégorie invalide : « name » et « icon » sont obligatoires.');

                return Command::FAILURE;
            }
            $categorySlug = Slug::fromText($category['name']);
            if (null === $this->categories->findBySlug($categorySlug)) {
                $io->writeln(\sprintf(' + catégorie <info>%s</info> (%s)', $category['name'], $categorySlug));
                if (!$dryRun) {
                    $this->commands->dispatch(new CreateCategory($category['name'], $category['icon'], featured: true === ($category['featured'] ?? false)));
                }
                ++$created['categories'];
            }
            foreach (\is_array($category['jobs'] ?? null) ? $category['jobs'] : [] as $job) {
                if (!\is_array($job) || !\is_string($job['name'] ?? null)) {
                    $io->error(\sprintf('Métier invalide dans « %s ».', $category['name']));

                    return Command::FAILURE;
                }
                if (null !== $this->jobs->findBySlug(Slug::fromText($job['name']))) {
                    continue;
                }
                $decennial = true === ($job['decennial'] ?? false);
                $io->writeln(\sprintf('   + métier %s%s', $job['name'], $decennial ? ' (décennale)' : ''));
                if (!$dryRun) {
                    $this->commands->dispatch(new CreateJob(
                        categorySlug: $categorySlug->value,
                        name: $job['name'],
                        synonyms: self::stringList($job['synonyms'] ?? []),
                        requiredDocuments: $decennial ? [...$baseDocuments, 'insurance_decennial'] : $baseDocuments,
                        decennialRequired: $decennial,
                    ));
                }
                ++$created['jobs'];
            }
        }

        $io->success(\sprintf('%s%d catégorie(s) et %d métier(s) ajouté(s).', $dryRun ? '[simulation] ' : '', $created['categories'], $created['jobs']));

        return Command::SUCCESS;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        return \is_array($value) ? array_values(array_filter($value, \is_string(...))) : [];
    }
}
