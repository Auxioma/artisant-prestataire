<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Event;

use App\Shared\Domain\Event\DomainEvent;

/** Modification affectant l'affichage public (libellé, ordre, activation, mise en avant). */
final readonly class CategoryChanged implements DomainEvent
{
    public function __construct(public string $categoryId, public string $change, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
