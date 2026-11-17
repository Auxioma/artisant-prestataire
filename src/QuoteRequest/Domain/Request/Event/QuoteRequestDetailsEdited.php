<?php

declare(strict_types=1);

namespace App\QuoteRequest\Domain\Request\Event;

use App\Shared\Domain\Event\DomainEvent;

/** Demande modifiée avant le premier devis reçu (RG-066). */
final readonly class QuoteRequestDetailsEdited implements DomainEvent
{
    public function __construct(public string $requestId, public string $clientId, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
