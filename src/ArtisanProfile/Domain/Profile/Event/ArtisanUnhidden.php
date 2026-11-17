<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Profile\Event;

use App\Shared\Domain\Event\DomainEvent;

/** Compte de nouveau visible (couverture d'assurance rétablie). */
final readonly class ArtisanUnhidden implements DomainEvent
{
    public function __construct(public string $profileId, public string $userId, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
