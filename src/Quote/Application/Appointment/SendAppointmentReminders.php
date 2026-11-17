<?php

declare(strict_types=1);

namespace App\Quote\Application\Appointment;

use App\Shared\Application\Bus\Command;

/** Chaque matin : rappel E-15 aux deux parties des rendez-vous confirmés du lendemain. */
final readonly class SendAppointmentReminders implements Command
{
}
