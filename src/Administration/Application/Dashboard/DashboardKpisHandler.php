<?php

declare(strict_types=1);

namespace App\Administration\Application\Dashboard;

use App\Shared\Application\Admin\DashboardMetricSource;
use App\Shared\Application\Bus\QueryHandler;

/** Agrège les indicateurs des modules pour le mois demandé et pour le même mois un an plus tôt, puis calcule les écarts. */
final readonly class DashboardKpisHandler implements QueryHandler
{
    private const string TIMEZONE = 'Europe/Paris';
    private const string MONTH_PATTERN = '/^\d{4}-(0[1-9]|1[0-2])$/';
    private const int PERCENT = 100;
    /** Référence nulle : pas d'écart relatif calculable. */
    private const float NONE = 0.0;
    /** Décimales en deçà desquelles un écart est affiché comme nul (ton neutre). */
    private const int DELTA_PRECISION = 1;

    /** @param iterable<DashboardMetricSource> $sources */
    public function __construct(private iterable $sources)
    {
    }

    public function __invoke(DashboardKpis $query): DashboardKpisView
    {
        $zone = new \DateTimeZone(self::TIMEZONE);
        $now = $query->now->setTimezone($zone);
        $current = $now->modify('first day of this month')->setTime(0, 0);
        $from = null !== $query->month && 1 === preg_match(self::MONTH_PATTERN, $query->month)
            ? new \DateTimeImmutable($query->month.'-01 00:00', $zone)
            : $current;
        $from = $from > $current ? $current : $from;
        $end = $from->modify('first day of next month');
        $to = $end > $now ? $now : $end;
        $previousFrom = $from->modify('-1 year');
        $previousTo = $to->modify('-1 year');

        $values = $this->collect($from, $to);
        $previous = $this->collect($previousFrom, $previousTo);
        $featured = [];
        $others = [];
        foreach (DashboardMetric::cases() as $metric) {
            $card = $this->card($metric, $values[$metric->value] ?? null, $metric->snapshotOnly() ? null : ($previous[$metric->value] ?? null));
            if ($metric->featured()) {
                $featured[] = $card;
            } else {
                $others[] = $card;
            }
        }

        return new DashboardKpisView($from->format('Y-m'), $from, $to, $previousFrom, $previousTo, $featured, $others);
    }

    /** @return array<string, int|float|null> */
    private function collect(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $values = [];
        foreach ($this->sources as $source) {
            $values += $source->metrics($from, $to);
        }

        return $values;
    }

    private function card(DashboardMetric $metric, int|float|null $value, int|float|null $previous): KpiCard
    {
        $unit = $metric->unit();
        $deltaUnit = match ($unit) {
            MetricUnit::Rate => 'pts',
            MetricUnit::Minutes => 'min',
            MetricUnit::Rating => '',
            default => '%',
        };
        $delta = null;
        if (null !== $value && null !== $previous) {
            $delta = match ($unit) {
                MetricUnit::Rate => ($value - $previous) * self::PERCENT,
                MetricUnit::Minutes, MetricUnit::Rating => (float) ($value - $previous),
                default => self::NONE === (float) $previous ? null : ($value - $previous) / $previous * self::PERCENT,
            };
        }
        $tone = null === $delta || self::NONE === round($delta, self::DELTA_PRECISION) ? 'neutral' : (($delta > 0) !== $metric->lowerIsBetter() ? 'good' : 'bad');

        return new KpiCard($metric, $value, $previous, $delta, $deltaUnit, $tone);
    }
}
