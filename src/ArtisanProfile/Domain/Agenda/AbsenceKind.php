<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Agenda;

/** PR06.1 / PR19 — exception à l'agenda : congé ou indisponibilité ponctuelle (fermeture, chantier hors plateforme…). */
enum AbsenceKind: string
{
    case Leave = 'leave';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::Leave => 'Congé',
            self::Unavailable => 'Indisponibilité',
        };
    }
}
