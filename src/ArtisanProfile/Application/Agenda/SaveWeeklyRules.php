<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Agenda;

use App\Shared\Application\Bus\Command;

/** PR16 « Horaires récurrents » — remplace les plages hebdomadaires de l'artisan. */
final readonly class SaveWeeklyRules implements Command
{
    /** @param list<array{weekday: int, start: string, end: string}> $rules heures HH:MM locales */
    public function __construct(public string $userId, public array $rules)
    {
    }
}
