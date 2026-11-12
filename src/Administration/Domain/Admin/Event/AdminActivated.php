<?php

declare(strict_types=1);

namespace App\Administration\Domain\Admin\Event;

use App\Shared\Domain\Event\DomainEvent;

/** Invitation acceptée : mot de passe et double authentification configurés. */
final readonly class AdminActivated implements DomainEvent
{
    public function __construct(public string $adminId, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
