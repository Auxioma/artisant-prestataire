<?php

declare(strict_types=1);

namespace App\Identity\Domain\Privacy;

use App\Shared\Domain\Event\DomainEvent;

/** C07.4 : archive de données demandée ; générée hors requête (worker). */
final readonly class DataExportRequested implements DomainEvent
{
    public function __construct(public string $exportId, public string $userId, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
