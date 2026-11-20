<?php

declare(strict_types=1);

namespace App\Shared\Application\Bus;

use App\Shared\Domain\Event\DomainEvent;

interface EventBus
{
    /**
     * Enregistre les événements dans l'outbox de la transaction courante (ADR-0011) ;
     * ils sont relayés vers les handlers asynchrones après commit.
     */
    public function publish(DomainEvent ...$events): void;
}
