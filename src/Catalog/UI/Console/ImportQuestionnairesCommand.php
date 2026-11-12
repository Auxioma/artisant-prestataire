<?php

declare(strict_types=1);

namespace App\Catalog\UI\Console;

use App\Catalog\Application\Questionnaire\ImportQuestionnaires;
use App\Shared\Application\Bus\CommandBus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/** P11.3 — questionnaires par métier en attendant le constructeur BO06. Idempotent : nouvelle version seulement si le schéma change. */
#[AsCommand(name: 'app:catalog:questionnaires', description: 'Importe les questionnaires de demande par métier (config/catalog/questionnaires.yaml par défaut)')]
final class ImportQuestionnairesCommand extends Command
{
    public function __construct(private readonly CommandBus $commands, #[Autowire('%kernel.project_dir%/config/catalog/questionnaires.yaml')] private readonly string $defaultFile)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::OPTIONAL, 'Fichier YAML');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $input->getArgument('file');
        $data = Yaml::parseFile(\is_string($file) ? $file : $this->defaultFile);
        $data = \is_array($data) ? $data : [];
        $this->commands->dispatch(new ImportQuestionnaires(self::fields($data['common'] ?? []), self::groups($data['categories'] ?? []), self::groups($data['jobs'] ?? [])));
        new SymfonyStyle($input, $output)->success('Questionnaires importés.');

        return Command::SUCCESS;
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function groups(mixed $groups): array
    {
        $clean = [];
        foreach (\is_array($groups) ? $groups : [] as $slug => $fields) {
            $clean[(string) $slug] = self::fields($fields);
        }

        return $clean;
    }

    /** @return list<array<string, mixed>> */
    private static function fields(mixed $fields): array
    {
        $clean = [];
        foreach (\is_array($fields) ? $fields : [] as $field) {
            if (\is_array($field)) {
                $row = [];
                foreach ($field as $k => $v) {
                    $row[(string) $k] = $v;
                }
                $clean[] = $row;
            }
        }

        return $clean;
    }
}
