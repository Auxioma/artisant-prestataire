<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Agenda;

/** Événements exportés : rendez-vous (créneaux retenus ou réservés) et absences (journées entières). */
final readonly class IcalFeedView
{
    /**
     * @param list<array{uid: string, start: \DateTimeImmutable, end: \DateTimeImmutable, summary: string, all_day: bool}> $events
     */
    public function __construct(public string $artisanId, public string $name, public array $events)
    {
    }
}
