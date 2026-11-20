<?php

declare(strict_types=1);

namespace App\Search\UI\Console;

use App\ArtisanProfile\Application\Search\PublicArtisanIds;
use App\ArtisanProfile\Application\Search\SearchableArtisan;
use App\ArtisanProfile\Application\Search\SearchableArtisanOf;
use App\Search\Application\Artisan\ArtisanSearchIndex;
use App\Shared\Application\Bus\QueryBus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Reconstruit la projection de recherche depuis les profils publics (après une migration ou un import). */
#[AsCommand(name: 'app:search:rebuild-artisans', description: 'Reconstruit search.artisan_search_view')]
final class RebuildArtisanSearchCommand extends Command
{
    public function __construct(private readonly QueryBus $queries, private readonly ArtisanSearchIndex $index)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ids = $this->queries->ask(new PublicArtisanIds());
        $count = 0;
        foreach (\is_array($ids) ? $ids : [] as $id) {
            $artisan = \is_string($id) ? $this->queries->ask(new SearchableArtisanOf($id)) : null;
            if ($artisan instanceof SearchableArtisan) {
                $this->index->save($artisan);
                ++$count;
            }
        }
        new SymfonyStyle($input, $output)->success(\sprintf('%d artisan(s) public(s) indexé(s).', $count));

        return Command::SUCCESS;
    }
}
