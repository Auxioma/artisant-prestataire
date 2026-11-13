<?php

declare(strict_types=1);

namespace App\Quote\Domain\Quote\Event;

use App\Shared\Domain\Event\DomainEvent;

/** RG-102 — nouvelle version envoyée d'un devis (gratuite, sans place supplémentaire). */
final readonly class QuoteRevised implements DomainEvent
{
    public function __construct(public string $quoteId, public string $requestId, public string $artisanId, public int $version, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
