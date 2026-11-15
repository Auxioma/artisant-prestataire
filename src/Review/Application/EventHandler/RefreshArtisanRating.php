<?php

declare(strict_types=1);

namespace App\Review\Application\EventHandler;

use App\Review\Application\Port\ReviewDirectory;
use App\Review\Domain\Review\Event\ReviewChanged;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\EventHandler;
use App\Shared\Application\Review\UpdateArtisanRating;

/** Toute évolution d'un avis recalcule la note publiée de l'artisan (recherche, fiche). */
final readonly class RefreshArtisanRating implements EventHandler
{
    public function __construct(private ReviewDirectory $directory, private CommandBus $commands)
    {
    }

    public function __invoke(ReviewChanged $event): void
    {
        $stats = $this->directory->publishedStats($event->artisanId);
        $this->commands->dispatch(new UpdateArtisanRating($event->artisanId, $stats['avg'], $stats['count']));
    }
}
