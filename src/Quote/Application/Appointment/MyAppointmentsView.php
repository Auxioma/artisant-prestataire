<?php

declare(strict_types=1);

namespace App\Quote\Application\Appointment;

final readonly class MyAppointmentsView
{
    /**
     * @param list<AppointmentView>                                         $rows
     * @param array{upcoming: int, past: int, cancelled: int, pending: int} $counts
     * @param list<AppointmentView>                                         $upcoming rendez-vous à venir (prochain, agenda du mois)
     */
    public function __construct(public array $rows, public array $counts, public array $upcoming)
    {
    }

    public function next(): ?AppointmentView
    {
        return $this->upcoming[0] ?? null;
    }

    /** @return array<string, string> date locale Y-m-d => statut (confirmed l'emporte) */
    public function days(): array
    {
        $days = [];
        foreach ($this->upcoming as $a) {
            $key = $a->startsAt->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d');
            $days[$key] = 'confirmed' === ($days[$key] ?? null) ? 'confirmed' : $a->status;
        }

        return $days;
    }
}
