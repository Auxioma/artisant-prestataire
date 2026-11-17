<?php

declare(strict_types=1);

namespace App\Quote\Application\Appointment;

use App\Shared\Application\Bus\Query;

/** C10 / PR06 — rendez-vous de l'utilisateur ; réponse : MyAppointmentsView. */
final readonly class MyAppointments implements Query
{
    public const array FILTERS = ['upcoming', 'past', 'cancelled'];

    /** @param 'client'|'artisan' $party */
    public function __construct(public string $userId, public string $party, public string $filter = 'upcoming', public string $query = '')
    {
    }
}
