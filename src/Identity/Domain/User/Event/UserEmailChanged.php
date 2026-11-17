<?php

declare(strict_types=1);

namespace App\Identity\Domain\User\Event;

use App\Shared\Domain\Event\DomainEvent;

/** Adresse e-mail changée et confirmée (A06) ; aucune adresse dans l'événement. */
final readonly class UserEmailChanged implements DomainEvent
{
    public function __construct(public string $userId, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
