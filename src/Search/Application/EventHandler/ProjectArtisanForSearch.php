<?php

declare(strict_types=1);

namespace App\Search\Application\EventHandler;

use App\ArtisanProfile\Application\Search\SearchableArtisan;
use App\ArtisanProfile\Application\Search\SearchableArtisanOf;
use App\ArtisanProfile\Domain\Agenda\Event\AgendaChanged;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanHidden;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanInsurancesDeclared;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanJobsChosen;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanLogoChanged;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanPublicProfileUpdated;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanReinstated;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanRejected;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanServiceAreaDefined;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanSignupSubmitted;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanSuspended;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanUnhidden;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanVerified;
use App\Search\Application\Artisan\ArtisanSearchIndex;
use App\Shared\Application\Bus\EventHandler;
use App\Shared\Application\Bus\QueryBus;

/**
 * Projection de recherche tenue à jour à chaque changement d'un profil : seuls les artisans publics y figurent (RG-043,
 * RG-130). Agenda modifié ou prolongé chaque nuit : badge « Disponible » recalculé (RG-013).
 */
final readonly class ProjectArtisanForSearch implements EventHandler
{
    public function __construct(private QueryBus $queries, private ArtisanSearchIndex $index)
    {
    }

    public function __invoke(ArtisanVerified|ArtisanSuspended|ArtisanReinstated|ArtisanRejected|ArtisanHidden|ArtisanUnhidden|ArtisanJobsChosen|ArtisanServiceAreaDefined|ArtisanInsurancesDeclared|ArtisanLogoChanged|ArtisanSignupSubmitted|ArtisanPublicProfileUpdated|AgendaChanged $event): void
    {
        $profileId = $event instanceof AgendaChanged ? $event->artisanId : $event->profileId;
        $artisan = $this->queries->ask(new SearchableArtisanOf($profileId));
        $artisan instanceof SearchableArtisan ? $this->index->save($artisan) : $this->index->remove($profileId);
    }
}
