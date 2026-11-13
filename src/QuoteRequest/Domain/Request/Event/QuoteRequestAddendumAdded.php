<?php

declare(strict_types=1);

namespace App\QuoteRequest\Domain\Request\Event;

use App\Shared\Domain\Event\DomainEvent;

/** C02 — complément ajouté par le client (E-32 aux artisans ayant déjà répondu, lot Devis). */
final readonly class QuoteRequestAddendumAdded implements DomainEvent
{
    public function __construct(public string $requestId, public string $clientId, public string $addendumId, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
