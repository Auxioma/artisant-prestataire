<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Event;

use App\Shared\Domain\Event\DomainEvent;

/** Modification d'un métier visible publiquement ou pour la recherche (libellé, synonymes, catégorie, justificatifs…). */
final readonly class JobChanged implements DomainEvent
{
    public function __construct(public string $jobId, public string $change, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
