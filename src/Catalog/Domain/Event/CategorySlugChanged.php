<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Event;

use App\Shared\Domain\Event\DomainEvent;

/** BO06 : slug modifié → redirection 301 créée automatiquement. */
final readonly class CategorySlugChanged implements DomainEvent
{
    public function __construct(public string $categoryId, public string $oldSlug, public string $newSlug, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
