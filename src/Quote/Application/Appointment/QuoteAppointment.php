<?php

declare(strict_types=1);

namespace App\Quote\Application\Appointment;

use App\Shared\Application\Bus\Query;

/** Dernier rendez-vous d'un devis vu par l'une de ses parties ; réponse : AppointmentView, ou null. */
final readonly class QuoteAppointment implements Query
{
    public function __construct(public string $userId, public string $quoteId)
    {
    }
}
