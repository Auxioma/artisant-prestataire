<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Agenda;

use App\ArtisanProfile\Domain\Agenda\Absence;
use App\ArtisanProfile\Domain\Agenda\AgendaSettings;
use App\ArtisanProfile\Domain\Agenda\WeeklyRule;

final readonly class AgendaCalendarView
{
    /**
     * @param list<AgendaDay>  $days
     * @param list<WeeklyRule> $rules
     * @param list<Absence>    $absences absences à venir
     */
    public function __construct(
        public string $artisanId,
        public AgendaSettings $settings,
        public array $rules,
        public array $absences,
        public array $days,
        public ?\DateTimeImmutable $nextOpen,
    ) {
    }

    /** Jours de la période avec au moins une plage ouverte. */
    public function availableDays(): int
    {
        return \count(array_filter($this->days, static fn (AgendaDay $d): bool => [] !== $d->open));
    }

    public function openHours(): float
    {
        return array_sum(array_map(static fn (AgendaDay $d): int => $d->openMinutes(), $this->days)) / 60;
    }

    /** Créneaux retenus ou réservés sur la période. */
    public function reserved(): int
    {
        return array_sum(array_map(static fn (AgendaDay $d): int => \count(array_filter($d->busy, static fn (array $b): bool => 'blocked' !== $b[2])), $this->days));
    }

    public function booked(): int
    {
        return array_sum(array_map(static fn (AgendaDay $d): int => \count(array_filter($d->busy, static fn (array $b): bool => 'booked' === $b[2])), $this->days));
    }

    /** @return list<WeeklyRule> */
    public function rulesOf(int $weekday): array
    {
        return array_values(array_filter($this->rules, static fn (WeeklyRule $r): bool => $r->weekday === $weekday));
    }
}
