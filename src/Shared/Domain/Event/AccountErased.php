<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event;

/**
 * RG-090 : compte effacé à l'issue du délai de rétractation. Chaque module retire ou neutralise ce qu'il détient
 * (demandes en cours annulées, favoris, notifications…) ; les données soumises à obligation légale restent.
 */
final readonly class AccountErased implements DomainEvent
{
    public function __construct(public string $userId, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
