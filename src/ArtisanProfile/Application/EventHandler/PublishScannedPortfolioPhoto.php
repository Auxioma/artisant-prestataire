<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\EventHandler;

use App\ArtisanProfile\Application\Port\PortfolioStore;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanPublicProfileUpdated;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Application\Bus\EventHandler;
use App\Shared\Domain\Storage\FileAvailable;
use App\Shared\Domain\Storage\FilePurpose;

/**
 * PR08.6 — une photo de réalisation devient publique une fois déclarée saine par l'antivirus : la complétude (objectif
 * `pro.completion_photos_target`) et la projection de recherche sont alors recalculées.
 */
final readonly class PublishScannedPortfolioPhoto implements EventHandler
{
    public function __construct(private PortfolioStore $portfolio, private EventBus $events)
    {
    }

    public function __invoke(FileAvailable $event): void
    {
        if (FilePurpose::PortfolioPhoto->value !== $event->purpose) {
            return;
        }
        $owner = $this->portfolio->ownerOfFile($event->fileId);
        if (null !== $owner) {
            $this->events->publish(new ArtisanPublicProfileUpdated($owner['profile_id'], $owner['user_id'], $event->occurredAt()));
        }
    }
}
