<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Profile\Event;

use App\Shared\Domain\Event\DomainEvent;

/** Compte suspendu (RG-043) : retiré de la recherche et de la diffusion — E-26. */
final readonly class ArtisanSuspended implements DomainEvent
{
    public function __construct(public string $profileId, public string $userId, public ?string $adminId, public string $reason, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
