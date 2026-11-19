<?php

declare(strict_types=1);

namespace App\Administration\Application\Dashboard;

/**
 * Une carte BO01.1. $delta est exprimé selon $deltaUnit : « % » (écart relatif), « pts » (points de pourcentage),
 * « min » ou « » (écart de note). $tone vaut good, bad ou neutral selon le sens favorable de l'indicateur.
 */
final readonly class KpiCard
{
    public function __construct(
        public DashboardMetric $metric,
        public int|float|null $value,
        public int|float|null $previous,
        public ?float $delta,
        public string $deltaUnit,
        public string $tone,
    ) {
    }

    public function key(): string
    {
        return $this->metric->value;
    }

    public function label(): string
    {
        return $this->metric->label();
    }

    public function unit(): string
    {
        return $this->metric->unit()->value;
    }
}
