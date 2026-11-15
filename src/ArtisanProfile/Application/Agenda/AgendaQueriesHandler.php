<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Agenda;

use App\ArtisanProfile\Application\Port\AgendaStore;
use App\ArtisanProfile\Domain\Agenda\FrenchHolidays;
use App\ArtisanProfile\Domain\Agenda\SlotPlanner;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Domain\Time\Clock;

/** PR16 — calendrier de l'artisan : plages ouvertes fusionnées par jour, plages occupées, absences, jours fériés. */
final readonly class AgendaQueriesHandler implements QueryHandler
{
    private const int MINUTES_PER_DAY = 1440;
    private const int MINUTE = 60;
    /** Recherche du prochain créneau ouvert. */
    private const string NEXT_WINDOW = '+60 days';

    public function __construct(private AgendaStore $store, private Clock $clock)
    {
    }

    public function __invoke(AgendaCalendar $query): ?AgendaCalendarView
    {
        $artisanId = $this->store->artisanOfUser($query->userId);
        if (null === $artisanId) {
            return null;
        }
        $zone = new \DateTimeZone(SlotPlanner::ZONE);
        $from = new \DateTimeImmutable($query->from->format('Y-m-d'), $zone);
        $to = new \DateTimeImmutable($query->to->format('Y-m-d'), $zone);
        if ($to < $from || $from->diff($to)->days >= AgendaCalendar::MAX_DAYS) {
            $to = $from->modify(\sprintf('+%d days', AgendaCalendar::MAX_DAYS - 1));
        }
        $settings = $this->store->settings($artisanId);
        $absences = $this->store->absences($artisanId, $from);
        $now = $this->clock->now()->setTimezone($zone);
        $slots = $this->store->slots($artisanId, $from, $to->modify('+1 day'));

        $days = [];
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $absence = null;
            foreach ($absences as $a) {
                $absence = $a->covers($day) ? $a : $absence;
            }
            $holiday = FrenchHolidays::of((int) $day->format('Y'))[$key] ?? null;
            $open = [];
            $busy = [];
            foreach ($slots as [$start, $end, $status]) {
                $local = $start->setTimezone($zone);
                if ($local->format('Y-m-d') !== $key) {
                    continue;
                }
                $s = (int) $local->format('G') * self::MINUTE + (int) $local->format('i');
                $e = min(self::MINUTES_PER_DAY, $s + intdiv($end->getTimestamp() - $start->getTimestamp(), self::MINUTE));
                if ('open' === $status) {
                    if ([] !== $open && $open[\count($open) - 1][1] === $s) {
                        $open[\count($open) - 1][1] = $e;
                    } else {
                        $open[] = [$s, $e];
                    }
                } else {
                    $busy[] = [$s, $e, $status];
                }
            }
            $days[] = new AgendaDay($day, $holiday, $absence, null !== $absence || (null !== $holiday && $settings->closedOnHolidays), $open, $busy);
        }

        return new AgendaCalendarView($artisanId, $settings, $this->store->rules($artisanId), $this->store->absences($artisanId, $now), $days, $this->nextOpen($artisanId, $now, $settings->minNoticeHours));
    }

    private function nextOpen(string $artisanId, \DateTimeImmutable $now, int $noticeHours): ?\DateTimeImmutable
    {
        $after = $now->modify(\sprintf('+%d hours', $noticeHours));
        foreach ($this->store->slots($artisanId, $after, $after->modify(self::NEXT_WINDOW)) as [$start, , $status]) {
            if ('open' === $status && $start >= $after) {
                return $start;
            }
        }

        return null;
    }
}
