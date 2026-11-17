<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Maintenance;

use App\ArtisanProfile\Domain\Document\Event\ArtisanDocumentReviewed;
use App\ArtisanProfile\Domain\Profile\ArtisanProfileId;
use App\ArtisanProfile\Domain\Profile\ArtisanProfileRepository;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanInsurancesDeclared;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Application\Bus\EventHandler;
use App\Shared\Domain\Time\Clock;

/** RG-130 — un compte masqué réapparaît dès que ses assurances obligatoires sont de nouveau déclarées et justifiées. */
final readonly class RestoreVisibility implements EventHandler
{
    public function __construct(private ArtisanProfileRepository $profiles, private InsuranceCoverage $coverage, private EventBus $events, private Clock $clock)
    {
    }

    public function __invoke(ArtisanDocumentReviewed|ArtisanInsurancesDeclared $event): void
    {
        $profile = $this->profiles->find(new ArtisanProfileId($event->profileId));
        if (null === $profile || !$profile->isHidden() || !$this->coverage->isCovered($profile, $this->clock->now())) {
            return;
        }
        $profile->unhide($this->clock->now());
        $this->events->publish(...$profile->releaseEvents());
    }
}
