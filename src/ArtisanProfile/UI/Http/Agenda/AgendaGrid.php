<?php

declare(strict_types=1);

namespace App\ArtisanProfile\UI\Http\Agenda;

use App\ArtisanProfile\Application\Agenda\AgendaDay;
use App\ArtisanProfile\Domain\Agenda\AbsenceKind;
use App\ArtisanProfile\Domain\Agenda\WeeklyRule;

/**
 * PR16 « Mon planning » (Figma 383:17652) — grille semaine en tableau : une ligne par pas (30 ou 15 min), une cellule
 * fusionnée (rowspan) par plage ; sans positionnement en ligne (la CSP interdit les styles en ligne).
 */
final class AgendaGrid
{
    private const int DEFAULT_FROM = 420;
    private const int DEFAULT_TO = 1260;
    private const int HOUR = 60;

    /**
     * @param list<AgendaDay> $days
     *
     * @return array{step: int, rows: list<array{time: ?string, cells: list<array{rows: int, state: string, label: string, range: ?string}|null>}>}
     */
    public static function build(array $days): array
    {
        $from = self::DEFAULT_FROM;
        $to = self::DEFAULT_TO;
        $step = 30;
        foreach ($days as $day) {
            foreach ([...$day->open, ...$day->busy] as $r) {
                $from = min($from, intdiv($r[0], self::HOUR) * self::HOUR);
                $to = max($to, (int) ceil($r[1] / self::HOUR) * self::HOUR);
                $step = 0 === $r[0] % 30 && 0 === $r[1] % 30 ? $step : 15;
            }
        }
        $count = intdiv($to - $from, $step);
        $grid = array_fill(0, $count, array_fill(0, \count($days), ['rows' => 1, 'state' => 'empty', 'label' => '', 'range' => null]));
        foreach ($days as $c => $day) {
            $segments = self::segments($day, $from, $to);
            foreach ($segments as [$s, $e, $state, $label]) {
                $first = intdiv(max($s, $from) - $from, $step);
                $last = (int) ceil((min($e, $to) - $from) / $step);
                if ($last <= $first) {
                    continue;
                }
                $grid[$first][$c] = ['rows' => $last - $first, 'state' => $state, 'label' => $label, 'range' => \in_array($state, ['closed', 'off', 'leave'], true) ? null : WeeklyRule::format($s).' – '.WeeklyRule::format($e)];
                for ($i = $first + 1; $i < $last; ++$i) {
                    $grid[$i][$c] = null;
                }
            }
        }
        $rows = [];
        foreach ($grid as $i => $cells) {
            $minute = $from + $i * $step;
            $rows[] = ['time' => 0 === $minute % self::HOUR ? WeeklyRule::format($minute) : null, 'cells' => array_values($cells)];
        }

        return ['step' => $step, 'rows' => $rows];
    }

    /** @return list<array{0: int, 1: int, 2: string, 3: string}> */
    private static function segments(AgendaDay $day, int $from, int $to): array
    {
        if ($day->closed) {
            return [[$from, $to, AbsenceKind::Leave === $day->absence?->kind ? 'leave' : 'closed', null !== $day->absence ? ($day->absence->label ?? $day->absence->kind->label()) : ($day->holiday ?? 'Indisponible')]];
        }
        if ([] === $day->open && [] === $day->busy) {
            return [[$from, $to, 'off', $day->holiday ?? 'Indisponible']];
        }
        $segments = array_map(static fn (array $r): array => [$r[0], $r[1], 'open', 'Disponible'], $day->open);
        foreach ($day->busy as [$s, $e, $status]) {
            $segments[] = [$s, $e, 'blocked' === $status ? 'closed' : ('held' === $status ? 'held' : 'busy'), ['held' => 'Proposé', 'booked' => 'Rendez-vous', 'blocked' => 'Bloqué'][$status] ?? 'Occupé'];
        }
        usort($segments, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $segments;
    }
}
