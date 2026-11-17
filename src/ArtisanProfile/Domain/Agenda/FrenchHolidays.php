<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Agenda;

/** Jours fériés légaux en métropole (code du travail, art. L3133-1) : 8 dates fixes et 3 dates liées à Pâques. */
final class FrenchHolidays
{
    private const array FIXED = ['01-01' => 'Jour de l’an', '05-01' => 'Fête du Travail', '05-08' => 'Victoire 1945', '07-14' => 'Fête nationale', '08-15' => 'Assomption', '11-01' => 'Toussaint', '11-11' => 'Armistice 1918', '12-25' => 'Noël'];
    /** Décalage en jours depuis le dimanche de Pâques. */
    private const array EASTER = [1 => 'Lundi de Pâques', 39 => 'Ascension', 50 => 'Lundi de Pentecôte'];

    /** @return array<string, string> date Y-m-d => libellé, dans l'ordre chronologique */
    public static function of(int $year): array
    {
        $days = [];
        foreach (self::FIXED as $md => $label) {
            $days[$year.'-'.$md] = $label;
        }
        $easter = self::easter($year);
        foreach (self::EASTER as $offset => $label) {
            $days[$easter->modify(\sprintf('+%d days', $offset))->format('Y-m-d')] = $label;
        }
        ksort($days);

        return $days;
    }

    public static function isHoliday(\DateTimeImmutable $day): bool
    {
        return isset(self::of((int) $day->format('Y'))[$day->format('Y-m-d')]);
    }

    /** Dimanche de Pâques (calendrier grégorien, algorithme de Meeus / Jones / Butcher). */
    private static function easter(int $y): \DateTimeImmutable
    {
        $a = $y % 19;
        $b = intdiv($y, 100);
        $c = $y % 100;
        $h = (19 * $a + $b - intdiv($b, 4) - intdiv(8 * $b + 13, 25) + 15) % 30;
        $l = (32 + 2 * ($b % 4) + 2 * intdiv($c, 4) - $h - $c % 4) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new \DateTimeImmutable(\sprintf('%04d-%02d-%02d', $y, $month, $day));
    }
}
