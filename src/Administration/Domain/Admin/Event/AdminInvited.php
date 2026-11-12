<?php

declare(strict_types=1);

namespace App\Administration\Domain\Admin\Event;

use App\Shared\Domain\Event\DomainEvent;

/** Administrateur invité (BO14.1) : e-mail d'invitation I-02. */
final readonly class AdminInvited implements DomainEvent
{
    public function __construct(public string $adminId, public ?string $invitedBy, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
