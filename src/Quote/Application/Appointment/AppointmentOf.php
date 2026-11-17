<?php

declare(strict_types=1);

namespace App\Quote\Application\Appointment;

use App\Shared\Application\Bus\Query;

/** Un rendez-vous vu par l'une de ses parties (fichier .ics « Ajouter à mon agenda ») ; réponse : AppointmentView, ou null. */
final readonly class AppointmentOf implements Query
{
    public function __construct(public string $userId, public string $appointmentId)
    {
    }
}
