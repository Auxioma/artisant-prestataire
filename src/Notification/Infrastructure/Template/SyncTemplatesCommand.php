<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Template;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** À chaque déploiement : catalogue de l'annexe F et contenus par défaut (sans écraser les modèles modifiés en BO11). */
#[AsCommand(name: 'app:notification:sync', description: 'Synchronise le catalogue des notifications (annexe F) et les modèles par défaut')]
final class SyncTemplatesCommand extends Command
{
    public function __construct(private readonly TemplateCatalogSync $sync)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $counts = $this->sync->sync();
        new SymfonyStyle($input, $output)->success(\sprintf('%d modèle(s) créé(s), %d mis à jour, %d personnalisé(s) conservé(s).', $counts['created'], $counts['updated'], $counts['customized']));

        return Command::SUCCESS;
    }
}
