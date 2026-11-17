<?php

declare(strict_types=1);

namespace App\Quote\Application\Appointment;

use App\ArtisanProfile\Application\Agenda\SlotReservations;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Domain\Time\Clock;

/** Lectures des rendez-vous, toujours du point de vue d'une partie du devis. */
final readonly class AppointmentQueriesHandler implements QueryHandler
{
    private const string ZONE = 'Europe/Paris';

    public function __construct(private AppointmentReader $reader, private AppointmentParties $parties, private SlotReservations $slots, private Clock $clock)
    {
    }

    public function __invoke(QuoteAppointment|MyAppointments|BookableSlots|AppointmentOf $query): AppointmentView|MyAppointmentsView|BookableSlotsView|null
    {
        $now = $this->clock->now();
        if ($query instanceof AppointmentOf) {
            $a = $this->reader->one($query->appointmentId);

            return null !== $a && \in_array($query->userId, [$a->clientUserId, $a->artisanUserId], true) ? $a : null;
        }
        if ($query instanceof MyAppointments) {
            $filter = \in_array($query->filter, MyAppointments::FILTERS, true) ? $query->filter : 'upcoming';

            return new MyAppointmentsView(
                $this->reader->forUser($query->userId, $query->party, $filter, trim($query->query), $now),
                $this->reader->counts($query->userId, $query->party, $now),
                $this->reader->forUser($query->userId, $query->party, 'upcoming', '', $now),
                'artisan' === $query->party ? $this->reader->toSchedule($query->userId) : [],
            );
        }
        $parties = $this->parties->ofQuote($query->quoteId);
        if (null === $parties || !\in_array($query->userId, [$parties['client_user_id'], $parties['artisan_user_id']], true)) {
            return null;
        }
        if ($query instanceof QuoteAppointment) {
            return $this->reader->forQuote($query->quoteId);
        }
        if ('accepted' !== $parties['quote_status']) {
            return null;
        }
        $party = $query->userId === $parties['client_user_id'] ? 'client' : 'artisan';
        $notice = $this->slots->noticeHours($parties['artisan_id']);
        $from = 'client' === $party ? $now->modify(\sprintf('+%d hours', $notice)) : $now;
        $days = [];
        foreach ($this->slots->openSlots($parties['artisan_id'], $from, $now->modify(\sprintf('+%d days', BookableSlots::DAYS))) as $slot) {
            $days[$slot[0]->setTimezone(new \DateTimeZone(self::ZONE))->format('Y-m-d')][] = $slot;
        }
        $current = $this->reader->forQuote($query->quoteId);

        return new BookableSlotsView($party, $parties['quote_id'], $parties['quote_number'], $parties['request_id'], $parties['request_number'], $parties['request_title'], $parties['artisan_name'], $parties['client_first_name'] ?? 'Client', $days, $notice, null !== $current && $current->isActive() ? $current : null);
    }
}
