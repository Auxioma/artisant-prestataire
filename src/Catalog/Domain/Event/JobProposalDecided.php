<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Event;

use App\Shared\Domain\Event\DomainEvent;

/**
 * Décision du back-office sur un métier proposé par un client : ajouté (`finalJobId` = le métier proposé, désormais
 * actif), rattaché à un métier existant (`finalJobId` = ce métier) ou refusé (`finalJobId` null). Les demandes en
 * attente sur le métier proposé sont alors publiées (ou refusées) par le module Demandes.
 */
final readonly class JobProposalDecided implements DomainEvent
{
    public function __construct(
        public string $proposedJobId,
        public string $proposedName,
        public ?string $finalJobId,
        public ?string $finalJobName,
        public ?string $note,
        public \DateTimeImmutable $at,
    ) {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
