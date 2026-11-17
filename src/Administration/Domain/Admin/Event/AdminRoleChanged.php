<?php

declare(strict_types=1);

namespace App\Administration\Domain\Admin\Event;

use App\Shared\Domain\Event\DomainEvent;

/** Rôle modifié (ré-authentification exigée). */
final readonly class AdminRoleChanged implements DomainEvent
{
    public function __construct(public string $adminId, public string $from, public string $to, public string $changedBy, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
