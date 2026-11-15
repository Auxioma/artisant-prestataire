<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Agenda;

use App\Shared\Application\Bus\Query;

/** PR16 — agenda de l'artisan connecté entre deux jours locaux inclus ; réponse : AgendaCalendarView, ou null. */
final readonly class AgendaCalendar implements Query
{
    /** Période affichable au plus (vue mois complète, semaines entamées comprises). */
    public const int MAX_DAYS = 42;

    public function __construct(public string $userId, public \DateTimeImmutable $from, public \DateTimeImmutable $to)
    {
    }
}
