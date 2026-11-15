<?php

declare(strict_types=1);

namespace App\Quote\Infrastructure\Scheduler;

use App\Quote\Application\Appointment\SendAppointmentReminders;
use App\Shared\Application\Bus\CommandBus;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/** Chaque jour à 9 h : rappel E-15 des rendez-vous confirmés du lendemain. */
#[AsCronTask('0 9 * * *', timezone: 'Europe/Paris')]
final readonly class AppointmentReminderTask
{
    public function __construct(private CommandBus $commands)
    {
    }

    public function __invoke(): void
    {
        $this->commands->dispatch(new SendAppointmentReminders());
    }
}
