<?php

declare(strict_types=1);

namespace App\Shared\Domain\Storage;

use App\Shared\Domain\Event\DomainEvent;

/** Fichier déposé en quarantaine : déclenche l’analyse antivirus asynchrone. */
final readonly class FileQuarantined implements DomainEvent
{
    public function __construct(public string $fileId, public string $purpose, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
