<?php

declare(strict_types=1);

namespace App\Catalog\UI\Console;

use App\Catalog\Application\Geo\GeoReferentialImporter;
use App\Catalog\Application\Geo\Port\GeoReferentialSourceFactory;
use App\Shared\Domain\Exception\InvalidValue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** BO07.1 : import / mise à jour du référentiel géographique (API officielle par défaut, ou fichier local). */
#[AsCommand(name: 'app:geo:import', description: 'Importe régions, départements et communes (codes postaux, centroïdes)')]
final class ImportGeoCommand extends Command
{
    public function __construct(
        private readonly GeoReferentialImporter $importer,
        private readonly GeoReferentialSourceFactory $sources,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Fichier JSON local {regions, departements, communes} au lieu de l’API')
            ->addOption('deactivate-missing', null, InputOption::VALUE_NONE, 'Désactive les communes absentes (import complet uniquement)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = $input->getOption('file');
        $source = \is_string($file) ? $this->sources->file($file) : $this->sources->official();
        $io->writeln(\is_string($file) ? 'Source : '.$file : 'Source : API Découpage administratif (geo.api.gouv.fr)');

        try {
            $report = $this->importer->import($source, (bool) $input->getOption('deactivate-missing'));
        } catch (InvalidValue $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Régions' => $report->regions],
            ['Départements' => $report->departments],
            ['Communes importées' => $report->communes],
            ['dont nouvelles' => $report->newCommunes],
            ['Communes désactivées' => $report->deactivated],
            ['Hors périmètre (collectivités d’outre-mer)' => $report->outOfScope],
            ['Enregistrements rejetés' => \count($report->rejected)],
        );
        foreach (\array_slice($report->rejected, 0, 10) as $reason) {
            $io->writeln(' <comment>rejet</comment> '.$reason);
        }
        $io->success('Référentiel géographique à jour.');

        return Command::SUCCESS;
    }
}
