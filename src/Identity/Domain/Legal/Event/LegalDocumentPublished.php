<?php

declare(strict_types=1);

namespace App\Identity\Domain\Legal\Event;

use App\Shared\Domain\Event\DomainEvent;

/** Nouvelle version d'un document légal (P17) : information E-95 pour les CGU / CGV, ré-acceptation si substantielle. */
final readonly class LegalDocumentPublished implements DomainEvent
{
    public function __construct(public string $documentId, public string $type, public int $version, public bool $substantial, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
