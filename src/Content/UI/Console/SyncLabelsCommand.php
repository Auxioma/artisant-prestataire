<?php

declare(strict_types=1);

namespace App\Content\UI\Console;

use App\Content\Application\Label\SyncLabels;
use App\Shared\Application\Bus\CommandBus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;

/**
 * BO10.6 — relève les appels `tm_label('cle', 'Texte')` des gabarits et les enregistre dans le dictionnaire (à lancer
 * au déploiement, comme `app:notification:sync`). Une même clé avec deux textes différents est refusée.
 */
#[AsCommand(name: 'app:labels:sync', description: 'Enregistre les libellés d’interface relevés dans les gabarits (BO10.6)')]
final class SyncLabelsCommand extends Command
{
    public const string PATTERN = "/tm_label\\(\\s*'([a-z0-9_]+(?:\\.[a-z0-9_]+)+)'\\s*,\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*\\)/u";

    public function __construct(private readonly CommandBus $commands, #[Autowire('%kernel.project_dir%/templates')] private readonly string $templates)
    {
        parent::__construct();
    }

    /** @return array<string, string> clé → texte d'origine */
    public static function scan(string $directory): array
    {
        $defaults = [];
        foreach (new Finder()->files()->in($directory)->name('*.twig') as $file) {
            preg_match_all(self::PATTERN, $file->getContents(), $matches, \PREG_SET_ORDER);
            foreach ($matches as [, $key, $text]) {
                $text = str_replace(["\\'", '\\\\'], ["'", '\\'], $text);
                if (isset($defaults[$key]) && $defaults[$key] !== $text) {
                    throw new \RuntimeException(\sprintf('Libellé « %s » : deux textes d’origine différents (%s).', $key, $file->getRelativePathname()));
                }
                $defaults[$key] = $text;
            }
        }
        ksort($defaults);

        return $defaults;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $defaults = self::scan($this->templates);
        $this->commands->dispatch(new SyncLabels($defaults));
        new SymfonyStyle($input, $output)->success(\sprintf('%d libellé(s) d’interface synchronisé(s).', \count($defaults)));

        return Command::SUCCESS;
    }
}
