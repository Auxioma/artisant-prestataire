<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Profile\Event;

use App\Shared\Domain\Event\DomainEvent;

/** Dossier refusé avec motif normalisé (RG-043) — E-22 (T-210). */
final readonly class ArtisanRejected implements DomainEvent
{
    public function __construct(public string $profileId, public string $userId, public string $adminId, public string $reason, public ?string $comment, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
