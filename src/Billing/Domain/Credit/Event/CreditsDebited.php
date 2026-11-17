<?php

declare(strict_types=1);

namespace App\Billing\Domain\Credit\Event;

use App\Shared\Domain\Event\DomainEvent;

/** RG-100 / RG-105 — un crédit vient d'être débité (envoi d'un devis) : solde du compartiment avant et après. */
final readonly class CreditsDebited implements DomainEvent
{
    public function __construct(
        public string $artisanId,
        public string $userId,
        public string $bucket,
        public int $bucketBefore,
        public int $bucketAfter,
        public int $freeGranted,
        public int $totalAfter,
        public \DateTimeImmutable $at,
    ) {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
