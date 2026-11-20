<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console;

use App\Shared\Infrastructure\Messenger\OutboxRelay;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:outbox:relay', description: 'Relaie les événements de l\'outbox vers Messenger')]
final class RelayOutboxCommand extends Command
{
    public function __construct(private readonly OutboxRelay $relay)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('once', null, InputOption::VALUE_NONE, 'Traite un seul lot puis s\'arrête')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Taille de lot', '100')
            ->addOption('sleep-ms', null, InputOption::VALUE_REQUIRED, 'Pause quand l\'outbox est vide', '500')
            ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Durée maximale (s) avant redémarrage par le superviseur', '3600');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batch = self::positiveInt($input->getOption('batch'), 'batch');
        $sleepMicroseconds = self::positiveInt($input->getOption('sleep-ms'), 'sleep-ms') * 1000;
        $deadline = time() + self::positiveInt($input->getOption('time-limit'), 'time-limit');

        do {
            $relayed = $this->relay->relayBatch($batch);
            if ($relayed > 0) {
                $output->writeln(\sprintf('%d événement(s) relayé(s)', $relayed), OutputInterface::VERBOSITY_VERBOSE);
            } elseif (!$input->getOption('once')) {
                usleep($sleepMicroseconds);
            }
        } while (!$input->getOption('once') && time() < $deadline);

        return Command::SUCCESS;
    }

    private static function positiveInt(mixed $option, string $name): int
    {
        $value = filter_var($option, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (false === $value) {
            throw new \InvalidArgumentException(\sprintf('L\'option --%s doit être un entier positif.', $name));
        }

        return $value;
    }
}
