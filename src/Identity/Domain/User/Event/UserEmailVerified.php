<?php

declare(strict_types=1);

namespace App\Identity\Domain\User\Event;

use App\Shared\Domain\Event\DomainEvent;

/** E-mail confirmé (A06). */
final readonly class UserEmailVerified implements DomainEvent
{
    public function __construct(public string $userId, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
