<?php

declare(strict_types=1);

namespace App\Billing\Domain\Plan\Event;

use App\Shared\Domain\Event\DomainEvent;

/** Un forfait a été créé, modifié, publié ou archivé (BO08.1) : à répercuter chez le prestataire de paiement. */
final readonly class PlanChanged implements DomainEvent
{
    public function __construct(public string $planId, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
