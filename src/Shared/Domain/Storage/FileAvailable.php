<?php

declare(strict_types=1);

namespace App\Shared\Domain\Storage;

use App\Shared\Domain\Event\DomainEvent;

/** Fichier sain et disponible : le module propriétaire peut l’exposer (après son Voter). */
final readonly class FileAvailable implements DomainEvent
{
    public function __construct(public string $fileId, public string $purpose, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
