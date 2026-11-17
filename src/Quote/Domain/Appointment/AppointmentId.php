<?php

declare(strict_types=1);

namespace App\Quote\Domain\Appointment;

final readonly class AppointmentId
{
    public function __construct(public string $value)
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value)) {
            throw new AppointmentRuleViolation('Rendez-vous introuvable.');
        }
    }
}
