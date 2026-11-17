<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Profile\Event;

use App\Shared\Domain\Event\DomainEvent;

/** Métiers choisis (A03.3) — la recherche et la diffusion suivent (T-208). */
final readonly class ArtisanJobsChosen implements DomainEvent
{
    public function __construct(public string $profileId, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
