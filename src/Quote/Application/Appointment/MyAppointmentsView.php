<?php

declare(strict_types=1);

namespace App\Quote\Application\Appointment;

final readonly class MyAppointmentsView
{
    /**
     * @param list<AppointmentView>                                                                                                                 $rows
     * @param array{upcoming: int, past: int, cancelled: int, pending: int}                                                                         $counts
     * @param list<AppointmentView>                                                                                                                 $upcoming   rendez-vous à venir (prochain, agenda du mois)
     * @param list<array{quote_id: string, request_id: string, request_number: string, request_title: ?string, client_name: string, city: ?string}> $toSchedule devis acceptés à planifier (artisan)
     */
    public function __construct(public array $rows, public array $counts, public array $upcoming, public array $toSchedule = [])
    {
    }

    /** Rendez-vous à venir (proposés ou confirmés) du jour, de la semaine ou du mois de `$now` (heure de Paris). */
    public function countIn(string $period, \DateTimeImmutable $now): int
    {
        $zone = new \DateTimeZone('Europe/Paris');
        $format = ['day' => 'Y-m-d', 'week' => 'o-W', 'month' => 'Y-m'][$period] ?? 'Y-m-d';
        $key = $now->setTimezone($zone)->format($format);

        return \count(array_filter($this->upcoming, static fn (AppointmentView $a): bool => $a->startsAt->setTimezone($zone)->format($format) === $key));
    }

    /** @return list<AppointmentView> rendez-vous à venir du jour de `$day` */
    public function on(\DateTimeImmutable $day): array
    {
        $zone = new \DateTimeZone('Europe/Paris');
        $key = $day->setTimezone($zone)->format('Y-m-d');

        return array_values(array_filter($this->upcoming, static fn (AppointmentView $a): bool => $a->startsAt->setTimezone($zone)->format('Y-m-d') === $key));
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
