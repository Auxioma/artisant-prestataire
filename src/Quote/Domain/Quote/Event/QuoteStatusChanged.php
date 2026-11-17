<?php

declare(strict_types=1);

namespace App\Quote\Domain\Quote\Event;

use App\Shared\Domain\Event\DomainEvent;

/** RG-121 — transition d'un devis (envoi, consultation, décision, expiration, retrait, demande annulée). */
final readonly class QuoteStatusChanged implements DomainEvent
{
    public function __construct(public string $quoteId, public string $requestId, public string $artisanId, public string $from, public string $to, public ?string $reason, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
