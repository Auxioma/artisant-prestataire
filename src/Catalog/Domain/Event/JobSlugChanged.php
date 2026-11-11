<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Event;

use App\Shared\Domain\Event\DomainEvent;

/** BO06 : slug modifié → redirections 301 (page métier et pages métier × ville). */
final readonly class JobSlugChanged implements DomainEvent
{
    public function __construct(public string $jobId, public string $oldSlug, public string $newSlug, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
