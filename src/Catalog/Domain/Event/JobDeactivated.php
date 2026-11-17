<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Event;

use App\Shared\Domain\Event\DomainEvent;

/**
 * BO06 : métier désactivé. Si un métier de remplacement est désigné, les modules utilisateurs
 * (profils artisans, demandes) migrent leurs références vers lui (assistant de migration).
 */
final readonly class JobDeactivated implements DomainEvent
{
    public function __construct(public string $jobId, public ?string $replacementJobId, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
