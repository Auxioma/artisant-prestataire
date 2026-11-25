<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Shared\Domain\Time\Clock;

final class FixedClock implements Clock
{
    public function __construct(private \DateTimeImmutable $now = new \DateTimeImmutable('2026-11-11 09:00:00', new \DateTimeZone('UTC')))
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
