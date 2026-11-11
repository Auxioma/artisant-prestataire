<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Time;

use App\Shared\Domain\Time\Clock;
use Psr\Clock\ClockInterface;

/** Adapte l'horloge Symfony (remplaçable par MockClock en test) ; toujours en UTC. */
final readonly class SystemClock implements Clock
{
    public function __construct(private ClockInterface $clock)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
    }
}
