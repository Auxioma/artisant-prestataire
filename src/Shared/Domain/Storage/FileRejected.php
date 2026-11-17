<?php

declare(strict_types=1);

namespace App\Shared\Domain\Storage;

use App\Shared\Domain\Event\DomainEvent;

/** Fichier écarté par l'antivirus : supprimé ; le module propriétaire prévient l'utilisateur. */
final readonly class FileRejected implements DomainEvent
{
    public function __construct(public string $fileId, public string $purpose, public string $reason, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
