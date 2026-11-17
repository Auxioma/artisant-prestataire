<?php

declare(strict_types=1);

namespace App\Quote\Domain\Appointment;

enum AppointmentParty: string
{
    case Client = 'client';
    case Artisan = 'artisan';

    public function other(): self
    {
        return self::Client === $this ? self::Artisan : self::Client;
    }
}
