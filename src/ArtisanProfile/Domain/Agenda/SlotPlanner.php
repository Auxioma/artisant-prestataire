<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Agenda;

/**
 * Créneaux ouverts d'un artisan sur une période (RG-025) : plages récurrentes découpées à la durée choisie, hors
 * absences, hors jours fériés si l'artisan ferme ces jours-là, hors créneaux déjà retenus ou réservés. Heures locales
 * (Europe/Paris, changements d'heure compris), bornes renvoyées en UTC.
 */
final class SlotPlanner
{
    public const string ZONE = 'Europe/Paris';

    /**
     * @param list<WeeklyRule>                                          $rules
     * @param list<Absence>                                             $absences
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $busy     plages occupées (UTC)
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> créneaux [début, fin[ en UTC, chronologiques
     */
    public static function plan(array $rules, array $absences, AgendaSettings $settings, array $busy, \DateTimeImmutable $fromDay, \DateTimeImmutable $toDay): array
    {
        $zone = new \DateTimeZone(self::ZONE);
        $utc = new \DateTimeZone('UTC');
        $slots = [];
        $day = new \DateTimeImmutable($fromDay->format('Y-m-d'), $zone);
        $last = $toDay->format('Y-m-d');
        while ($day->format('Y-m-d') <= $last) {
            if (!self::closed($day, $absences, $settings)) {
                $weekday = (int) $day->format('N');
                foreach ($rules as $rule) {
                    if ($rule->weekday !== $weekday) {
                        continue;
                    }
                    for ($t = $rule->start; $t + $settings->slotMinutes <= $rule->end; $t += $settings->slotMinutes) {
                        $start = $day->setTime(intdiv($t, 60), $t % 60)->setTimezone($utc);
                        $end = $day->setTime(intdiv($t + $settings->slotMinutes, 60) % 24, ($t + $settings->slotMinutes) % 60);
                        $end = ($t + $settings->slotMinutes >= 1440 ? $end->modify('+1 day') : $end)->setTimezone($utc);
                        if (!self::overlapsAny($start, $end, $busy)) {
                            $slots[] = [$start, $end];
                        }
                    }
                }
            }
            $day = $day->modify('+1 day');
        }
        usort($slots, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $slots;
    }

    /** @param list<Absence> $absences */
    public static function closed(\DateTimeImmutable $day, array $absences, AgendaSettings $settings): bool
    {
        if ($settings->closedOnHolidays && FrenchHolidays::isHoliday($day)) {
            return true;
        }
        foreach ($absences as $absence) {
            if ($absence->covers($day)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $busy */
    private static function overlapsAny(\DateTimeImmutable $start, \DateTimeImmutable $end, array $busy): bool
    {
        foreach ($busy as [$s, $e]) {
            if ($start < $e && $s < $end) {
                return true;
            }
        }

        return false;
    }
}
