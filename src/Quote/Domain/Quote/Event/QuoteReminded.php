<?php

declare(strict_types=1);

namespace App\Quote\Domain\Quote\Event;

use App\Shared\Domain\Event\DomainEvent;

/** P-11 — l'artisan relance le client sur un devis envoyé et non consulté (relance gratuite, nombre limité). */
final readonly class QuoteReminded implements DomainEvent
{
    public function __construct(public string $quoteId, public string $requestId, public string $artisanId, public int $reminder, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
