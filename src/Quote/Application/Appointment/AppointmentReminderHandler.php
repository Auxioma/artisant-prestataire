<?php

declare(strict_types=1);

namespace App\Quote\Application\Appointment;

use App\Quote\Domain\Appointment\AppointmentRepository;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Time\Clock;

/** E-15 — rappel J-1 aux deux parties des rendez-vous confirmés du lendemain (heure de Paris), une seule fois. */
final readonly class AppointmentReminderHandler implements CommandHandler
{
    private const string ZONE = 'Europe/Paris';

    public function __construct(private AppointmentRepository $appointments, private AppointmentReader $reader, private AppointmentMailer $mailer, private Clock $clock)
    {
    }

    public function __invoke(SendAppointmentReminders $command): void
    {
        $now = $this->clock->now();
        $tomorrow = $now->setTimezone(new \DateTimeZone(self::ZONE))->setTime(0, 0)->modify('+1 day');
        foreach ($this->appointments->dueForReminder($tomorrow, $tomorrow->modify('+1 day')) as $due) {
            $a = $this->reader->one($due['id']);
            if (null !== $a) {
                if (null !== $a->clientEmail) {
                    $this->mailer->reminder('client', $a->clientEmail, $a->clientFirstName, $a->clientUserId, $a->artisanName, $a->startsAt, $a->endsAt, $a->requestId, $a->requestNumber);
                }
                if (null !== $a->artisanEmail) {
                    $this->mailer->reminder('artisan', $a->artisanEmail, $a->artisanFirstName, $a->artisanUserId, $a->clientFirstName ?? 'Votre client', $a->startsAt, $a->endsAt, $a->requestId, $a->requestNumber);
                }
            }
            $this->appointments->markReminded($due['id'], $now);
        }
    }
}
