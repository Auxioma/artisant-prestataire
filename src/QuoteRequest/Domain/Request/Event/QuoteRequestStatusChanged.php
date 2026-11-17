<?php

declare(strict_types=1);

namespace App\QuoteRequest\Domain\Request\Event;

use App\Shared\Domain\Event\DomainEvent;

/** Transition RG-066 (hors envoi) : approbation, refus, complète, attribution, expiration, annulation, relance, réouverture. */
final readonly class QuoteRequestStatusChanged implements DomainEvent
{
    public function __construct(public string $requestId, public string $clientId, public string $from, public string $to, public string $reason, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
