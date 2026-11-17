<?php

declare(strict_types=1);

namespace App\Identity\Domain\User\Event;

use App\Shared\Domain\Event\DomainEvent;

/** Données personnelles effacées ; les modules anonymisent leurs propres données liées. */
final readonly class UserAnonymized implements DomainEvent
{
    public function __construct(public string $userId, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
