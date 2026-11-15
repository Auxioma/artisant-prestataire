<?php

declare(strict_types=1);

namespace App\Quote\Application\EventHandler;

use App\Quote\Application\Appointment\AppointmentMailer;
use App\Quote\Application\Appointment\AppointmentReader;
use App\Quote\Domain\Appointment\Event\AppointmentChanged;
use App\Shared\Application\Bus\EventHandler;

/**
 * E-14 / E-36 — l'autre partie est prévenue de chaque proposition, confirmation, annulation ou réalisation ; une
 * expiration (RG-073) prévient les deux. Rien n'est envoyé à l'auteur de l'action.
 */
final readonly class NotifyAppointmentChange implements EventHandler
{
    public function __construct(private AppointmentReader $reader, private AppointmentMailer $mailer)
    {
    }

    public function __invoke(AppointmentChanged $event): void
    {
        $a = $this->reader->one($event->appointmentId);
        if (null === $a) {
            return;
        }
        if ('artisan' !== $event->actor && null !== $a->artisanEmail) {
            $this->mailer->changed('artisan', $a->artisanEmail, $a->artisanFirstName, $a->artisanUserId, $event->change, $a->clientFirstName ?? 'Votre client', $a->startsAt, $a->endsAt, $a->requestId, $a->requestNumber, $event->reason);
        }
        if ('client' !== $event->actor && null !== $a->clientEmail) {
            $this->mailer->changed('client', $a->clientEmail, $a->clientFirstName, $a->clientUserId, $event->change, $a->artisanName, $a->startsAt, $a->endsAt, $a->requestId, $a->requestNumber, $event->reason);
        }
    }
}
