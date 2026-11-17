<?php

declare(strict_types=1);

namespace App\Administration\Infrastructure\Console;

use App\Shared\Application\Settings\SettingKey;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Initialise admin.setting avec les valeurs par défaut du catalogue (annexe G + points en suspens).
 * Idempotent : n'écrase jamais une valeur modifiée en BO. À exécuter à chaque déploiement, après les migrations.
 */
#[AsCommand(name: 'app:settings:sync', description: 'Ajoute les paramètres manquants avec leur valeur par défaut')]
final class SyncSettingsCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format(\DATE_ATOM);

        $inserted = $this->connection->transactional(static function (Connection $connection) use ($now): int {
            $count = 0;
            foreach (SettingKey::cases() as $key) {
                $definition = $key->definition();
                $value = json_encode($definition->normalize($key, $definition->default), \JSON_THROW_ON_ERROR);
                $count += (int) $connection->executeStatement(
                    'INSERT INTO admin.setting (key, value, type, updated_by, updated_at) VALUES (:key, :value, :type, NULL, :at) ON CONFLICT (key) DO NOTHING',
                    ['key' => $key->value, 'value' => $value, 'type' => $definition->type->value, 'at' => $now],
                );
            }

            return $count;
        });

        $io->success(\sprintf('%d paramètre(s) ajouté(s), %d au catalogue.', $inserted, \count(SettingKey::cases())));

        return Command::SUCCESS;
    }
}
