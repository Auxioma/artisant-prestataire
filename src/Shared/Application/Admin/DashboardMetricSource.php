<?php

declare(strict_types=1);

namespace App\Shared\Application\Admin;

/**
 * BO01.1 — indicateurs fournis par chaque module pour une période [from, to[ (le tableau de bord compare avec la même
 * période un an plus tôt). Valeur null : non calculable (aucune donnée de référence).
 */
interface DashboardMetricSource
{
    public const string TAG = 'app.dashboard_metric_source';

    /** @return array<string, int|float|null> clé d'indicateur (voir DashboardMetric) => valeur */
    public function metrics(\DateTimeImmutable $from, \DateTimeImmutable $to): array;
}
