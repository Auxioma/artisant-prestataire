<?php

declare(strict_types=1);

namespace App\Quote\Domain\Appointment\Event;

use App\Shared\Domain\Event\DomainEvent;

/**
 * Rendez-vous proposé, confirmé, reporté, annulé, expiré ou réalisé : l'autre partie est prévenue (E-14 au client si
 * l'artisan agit, E-36 à l'artisan si le client agit).
 */
final readonly class AppointmentChanged implements DomainEvent
{
    public function __construct(
        public string $appointmentId,
        public string $quoteId,
        public string $change,
        public ?string $actor,
        public ?string $reason,
        public \DateTimeImmutable $at,
    ) {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
