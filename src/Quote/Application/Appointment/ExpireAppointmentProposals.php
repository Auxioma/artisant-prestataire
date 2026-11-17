<?php

declare(strict_types=1);

namespace App\Quote\Application\Appointment;

use App\Shared\Application\Bus\Command;

/** Toutes les heures : propositions non confirmées dans `appointments.hold_hours` → expirées, créneau rouvert (RG-073). */
final readonly class ExpireAppointmentProposals implements Command
{
}
