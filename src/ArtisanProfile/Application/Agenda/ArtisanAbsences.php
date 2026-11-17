<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Agenda;

use App\Shared\Application\Bus\Query;

/** PR19 — toutes les absences de l'artisan connecté (passées et à venir) ; réponse : list<Absence>, ou null. */
final readonly class ArtisanAbsences implements Query
{
    public function __construct(public string $userId)
    {
    }
}
