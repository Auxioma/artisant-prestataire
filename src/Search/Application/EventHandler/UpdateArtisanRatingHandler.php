<?php

declare(strict_types=1);

namespace App\Search\Application\EventHandler;

use App\Search\Application\Artisan\ArtisanSearchIndex;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Review\UpdateArtisanRating;

/** Report de la note des avis publiés dans la projection de recherche. */
final readonly class UpdateArtisanRatingHandler implements CommandHandler
{
    public function __construct(private ArtisanSearchIndex $index)
    {
    }

    public function __invoke(UpdateArtisanRating $command): void
    {
        $this->index->updateRating($command->artisanId, $command->average, $command->count);
    }
}
