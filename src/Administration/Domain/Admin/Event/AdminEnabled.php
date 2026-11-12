<?php

declare(strict_types=1);

namespace App\Administration\Domain\Admin\Event;

use App\Shared\Domain\Event\DomainEvent;

/** Administrateur réactivé. */
final readonly class AdminEnabled implements DomainEvent
{
    public function __construct(public string $adminId, public string $enabledBy, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
