<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Agenda;

use App\ArtisanProfile\Domain\Agenda\Absence;

/** Une journée de l'agenda : plages ouvertes fusionnées et plages occupées, en minutes locales depuis minuit. */
final readonly class AgendaDay
{
    /**
     * @param list<array{0: int, 1: int}>            $open
     * @param list<array{0: int, 1: int, 2: string}> $busy [début, fin, statut held|booked|blocked]
     */
    public function __construct(
        public \DateTimeImmutable $date,
        public ?string $holiday,
        public ?Absence $absence,
        public bool $closed,
        public array $open,
        public array $busy,
    ) {
    }

    public function openMinutes(): int
    {
        return array_sum(array_map(static fn (array $r): int => $r[1] - $r[0], $this->open));
    }

    public function isToday(\DateTimeImmutable $now): bool
    {
        return $this->date->format('Y-m-d') === $now->format('Y-m-d');
    }
}
