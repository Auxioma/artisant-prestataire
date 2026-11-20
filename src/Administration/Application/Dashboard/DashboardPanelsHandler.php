<?php

declare(strict_types=1);

namespace App\Administration\Application\Dashboard;

use App\Shared\Application\Admin\AdminQueueSource;
use App\Shared\Application\Admin\DashboardDailySource;
use App\Shared\Application\Bus\QueryHandler;

/** BO01.2 et BO01.3 — files d'attente et activité quotidienne agrégées depuis les modules. */
final readonly class DashboardPanelsHandler implements QueryHandler
{
    private const string TIMEZONE = 'Europe/Paris';

    /**
     * @param iterable<AdminQueueSource>     $queues
     * @param iterable<DashboardDailySource> $daily
     */
    public function __construct(private iterable $queues, private iterable $daily)
    {
    }

    /** @return array<string, int>|DashboardActivityView */
    public function __invoke(DashboardQueues|DashboardActivity $query): array|DashboardActivityView
    {
        if ($query instanceof DashboardQueues) {
            $counts = [];
            foreach ($this->queues as $source) {
                $counts += $source->queues();
            }

            return $counts;
        }

        $series = [];
        foreach ($this->daily as $source) {
            $series += $source->daily($query->from, $query->to);
        }
        $zone = new \DateTimeZone(self::TIMEZONE);
        $day = $query->from->setTimezone($zone)->setTime(0, 0);
        $days = [];
        $max = 0;
        while ($day < $query->to) {
            $key = $day->format('Y-m-d');
            $requests = $series['requests'][$key] ?? 0;
            $quotes = $series['quotes_sent'][$key] ?? 0;
            $days[] = ['day' => $day, 'requests' => $requests, 'quotes' => $quotes];
            $max = max($max, $requests, $quotes);
            $day = $day->modify('+1 day');
        }

        return new DashboardActivityView($days, $max);
    }
}
