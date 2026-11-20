<?php

declare(strict_types=1);

namespace App\Administration\Application\Dashboard;

final readonly class DashboardActivityView
{
    /** @param list<array{day: \DateTimeImmutable, requests: int, quotes: int}> $days */
    public function __construct(public array $days, public int $max)
    {
    }
}
