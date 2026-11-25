<?php

declare(strict_types=1);

namespace App\Shared\Application\Admin;

/** BO01.3 — séries quotidiennes du graphique « Évolution quotidienne » fournies par chaque module. */
interface DashboardDailySource
{
    public const string TAG = 'app.dashboard_daily_source';

    /**
     * Jours en heure de Paris (« 2026-10-01 »), jours sans activité omis.
     *
     * @return array<string, array<string, int>> série => jour => nombre
     */
    public function daily(\DateTimeImmutable $from, \DateTimeImmutable $to): array;
}
