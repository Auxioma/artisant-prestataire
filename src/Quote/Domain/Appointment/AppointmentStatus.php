<?php

declare(strict_types=1);

namespace App\Quote\Domain\Appointment;

/** C03.6 / PR06.2 — proposé → confirmé → réalisé ; annulé, reporté (remplacé par une nouvelle proposition), expiré (RG-073). */
enum AppointmentStatus: string
{
    case Proposed = 'proposed';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Rescheduled = 'rescheduled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Proposed => 'En attente de confirmation',
            self::Confirmed => 'Confirmé',
            self::Completed => 'Réalisé',
            self::Cancelled => 'Annulé',
            self::Rescheduled => 'Reporté',
            self::Expired => 'Expiré',
        };
    }

    public function isActive(): bool
    {
        return self::Proposed === $this || self::Confirmed === $this;
    }
}
