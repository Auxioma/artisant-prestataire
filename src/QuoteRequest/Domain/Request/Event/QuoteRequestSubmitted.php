<?php

declare(strict_types=1);

namespace App\QuoteRequest\Domain\Request\Event;

use App\Shared\Domain\Event\DomainEvent;

/** Demande envoyée : publiée (ouverte) ou mise en modération selon le score de risque (RG-063). */
final readonly class QuoteRequestSubmitted implements DomainEvent
{
    public function __construct(public string $requestId, public string $clientId, public string $jobId, public string $cityInsee, public string $status, public int $maxQuotes, public ?string $targetArtisanId, public bool $broadcastToOthers, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
