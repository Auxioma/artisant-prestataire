<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Shared\Application\Bus\EventBus;
use App\Shared\Domain\Event\DomainEvent;

final class RecordingEventBus implements EventBus
{
    /** @var list<DomainEvent> */
    public array $published = [];

    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->published[] = $event;
        }
    }
}
