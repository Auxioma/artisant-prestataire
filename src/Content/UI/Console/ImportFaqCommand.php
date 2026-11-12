<?php

declare(strict_types=1);

namespace App\Content\UI\Console;

use App\Content\Application\Faq\ImportFaq;
use App\Shared\Application\Bus\CommandBus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/** P14 — importe le contenu de l'aide (YAML) en attendant l'éditeur BO10.4 (T-801). Idempotent : mise à jour par slug. */
#[AsCommand(name: 'app:faq:import', description: 'Importe les rubriques et questions de l’aide (config/content/faq.yaml par défaut)')]
final class ImportFaqCommand extends Command
{
    public function __construct(private readonly CommandBus $commands, #[Autowire('%kernel.project_dir%/config/content/faq.yaml')] private readonly string $defaultFile)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::OPTIONAL, 'Fichier YAML');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = $input->getArgument('file');
        $data = Yaml::parseFile(\is_string($file) ? $file : $this->defaultFile);
        $topics = \is_array($data) && \is_array($data['topics'] ?? null) ? $data['topics'] : [];
        $clean = [];
        foreach ($topics as $topic) {
            if (!\is_array($topic)) {
                continue;
            }
            $questions = [];
            foreach (\is_array($topic['questions'] ?? null) ? $topic['questions'] : [] as $q) {
                if (\is_array($q)) {
                    $questions[] = ['slug' => self::text($q, 'slug'), 'audience' => self::text($q, 'audience'), 'question' => self::text($q, 'question'), 'answer' => self::text($q, 'answer')];
                }
            }
            $clean[] = ['slug' => self::text($topic, 'slug'), 'title' => self::text($topic, 'title'), 'description' => self::text($topic, 'description'), 'icon' => self::text($topic, 'icon'), 'questions' => $questions];
        }
        $this->commands->dispatch(new ImportFaq($clean));
        $io->success(\sprintf('%d rubrique(s) et %d question(s) importées.', \count($clean), array_sum(array_map(static fn (array $t): int => \count($t['questions']), $clean))));

        return Command::SUCCESS;
    }

    /** @param array<array-key, mixed> $row */
    private static function text(array $row, string $key): string
    {
        return \is_string($row[$key] ?? null) ? trim($row[$key]) : '';
    }
}
