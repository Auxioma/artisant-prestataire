<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Event;

use App\Shared\Domain\Event\DomainEvent;

final readonly class JobCreated implements DomainEvent
{
    public function __construct(public string $jobId, public string $categoryId, public string $slug, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
