<?php

declare(strict_types=1);

namespace App\Quote\Application\Appointment;

/** E-14 (au client, quand l'artisan agit), E-36 (à l'artisan, quand le client agit), E-15 (rappel J-1, aux deux). */
interface AppointmentMailer
{
    /**
     * @param 'client'|'artisan' $recipient
     * @param string             $change    proposed, confirmed, cancelled, expired, completed, rescheduled
     */
    public function changed(string $recipient, string $email, ?string $firstName, string $userId, string $change, string $counterpart, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, string $requestId, string $requestNumber, ?string $reason): void;

    /** @param 'client'|'artisan' $recipient */
    public function reminder(string $recipient, string $email, ?string $firstName, string $userId, string $counterpart, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, string $requestId, string $requestNumber): void;
}
