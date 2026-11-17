<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Profile\Event;

use App\Shared\Domain\Event\DomainEvent;

/** Profil professionnel créé (A03, après l’étape 1). */
final readonly class ArtisanProfileStarted implements DomainEvent
{
    public function __construct(public string $profileId, public string $userId, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
