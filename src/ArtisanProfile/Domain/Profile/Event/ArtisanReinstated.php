<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Profile\Event;

use App\Shared\Domain\Event\DomainEvent;

/** Suspension levée : retour au statut précédent (vérifié ou en attente) — E-26. */
final readonly class ArtisanReinstated implements DomainEvent
{
    public function __construct(public string $profileId, public string $userId, public ?string $adminId, public string $status, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
