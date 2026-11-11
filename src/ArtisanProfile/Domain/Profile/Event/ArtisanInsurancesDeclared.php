<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Profile\Event;

use App\Shared\Domain\Event\DomainEvent;

/** Assurances et qualifications déclarées (A03.5) — « vérifiées » seulement après contrôle (RG-032). */
final readonly class ArtisanInsurancesDeclared implements DomainEvent
{
    public function __construct(public string $profileId, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
