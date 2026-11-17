<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Agenda\Event;

use App\Shared\Domain\Event\DomainEvent;

/** Créneaux ouverts ou mode pause modifiés : la recherche recalcule disponibilité et badge (RG-013, RG-025). */
final readonly class AgendaChanged implements DomainEvent
{
    public function __construct(public string $artisanId, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
