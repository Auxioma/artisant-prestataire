<?php

declare(strict_types=1);

namespace App\Administration\Domain\Admin\Event;

use App\Shared\Domain\Event\DomainEvent;

/** Administrateur désactivé : sessions refusées dès la requête suivante. */
final readonly class AdminDisabled implements DomainEvent
{
    public function __construct(public string $adminId, public string $disabledBy, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
