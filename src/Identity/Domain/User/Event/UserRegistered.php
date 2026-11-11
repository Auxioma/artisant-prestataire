<?php

declare(strict_types=1);

namespace App\Identity\Domain\User\Event;

use App\Shared\Domain\Event\DomainEvent;

/** Compte créé (A02/A03) — aucune donnée personnelle dans l’événement (outbox, journaux). */
final readonly class UserRegistered implements DomainEvent
{
    public function __construct(public string $userId, public string $kind, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
