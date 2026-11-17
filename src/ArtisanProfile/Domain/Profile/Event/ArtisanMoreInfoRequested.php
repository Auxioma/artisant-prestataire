<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Profile\Event;

use App\Shared\Domain\Event\DomainEvent;

/** Pièce ou information complémentaire demandée (BO03) ; le dossier reste en attente — E-22 (T-210). */
final readonly class ArtisanMoreInfoRequested implements DomainEvent
{
    public function __construct(public string $profileId, public string $userId, public string $adminId, public string $message, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
