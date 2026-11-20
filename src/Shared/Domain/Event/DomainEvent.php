<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event;

/**
 * Événement de domaine. Convention : propriétés scalaires ou \DateTimeImmutable uniquement,
 * pour une sérialisation JSON sûre (outbox, transports Messenger).
 */
interface DomainEvent
{
    public function occurredAt(): \DateTimeImmutable;
}
