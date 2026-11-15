<?php

declare(strict_types=1);

namespace App\Quote\Application\Appointment;

use App\Shared\Application\Bus\Command;

/**
 * C03.6 / PR06.2 — proposition d'un créneau ouvert de l'agenda de l'artisan pour un devis accepté ; avec
 * `replacesId`, report du rendez-vous en cours (il passe « reporté » et son créneau est rouvert).
 */
final readonly class ProposeAppointment implements Command
{
    public function __construct(
        public string $userId,
        public string $appointmentId,
        public string $quoteId,
        public string $startsAt,
        public ?string $note = null,
        public ?string $replacesId = null,
    ) {
    }
}
