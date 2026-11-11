<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use App\Shared\Domain\Event\DomainEvent;

/**
 * Racine d'agrégat : collecte les événements de domaine, publiés après commit par la couche Application.
 */
abstract class AggregateRoot
{
    /** @var list<DomainEvent> */
    private array $recordedEvents = [];

    final protected function recordEvent(DomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
    }

    /**
     * @return list<DomainEvent>
     */
    final public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }
}
