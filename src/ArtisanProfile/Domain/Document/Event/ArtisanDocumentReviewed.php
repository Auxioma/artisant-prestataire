<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Document\Event;

use App\Shared\Domain\Event\DomainEvent;

/** Justificatif validé ou refusé par un administrateur (BO03) — E-24. */
final readonly class ArtisanDocumentReviewed implements DomainEvent
{
    public function __construct(
        public string $documentId,
        public string $profileId,
        public string $type,
        public ?string $subject,
        public string $status,
        public ?string $reason,
        public string $adminId,
        public \DateTimeImmutable $at,
    ) {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
