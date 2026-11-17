<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Profile\Event;

use App\Shared\Domain\Event\DomainEvent;

/** Compte masqué automatiquement (RG-130 : assurance obligatoire expirée depuis plus du délai de grâce). */
final readonly class ArtisanHidden implements DomainEvent
{
    public function __construct(public string $profileId, public string $userId, public string $reason, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
