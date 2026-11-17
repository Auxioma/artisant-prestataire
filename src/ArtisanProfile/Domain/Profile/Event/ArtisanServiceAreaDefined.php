<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Profile\Event;

use App\Shared\Domain\Event\DomainEvent;

/** Zone d'intervention définie ou modifiée (A03.4, RG-042) — effet immédiat sur la diffusion (matérialisation : T-208). */
final readonly class ArtisanServiceAreaDefined implements DomainEvent
{
    public function __construct(public string $profileId, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
