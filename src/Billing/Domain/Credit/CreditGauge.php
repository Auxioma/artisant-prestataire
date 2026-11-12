<?php

declare(strict_types=1);

namespace App\Billing\Domain\Credit;

/**
 * État de la jauge de crédits (Figma 74:235, PR01.1, RG-105) : seuils de consommation du crédit gratuit administrables
 * (`credits.alert_thresholds`, % consommés) — normal · alert · critical · exhausted.
 */
final readonly class CreditGauge
{
    public const string NORMAL = 'normal';
    public const string ALERT = 'alert';
    public const string CRITICAL = 'critical';
    public const string EXHAUSTED = 'exhausted';
    private const int PERCENT = 100;

    public string $state;

    /** @param list<int> $thresholds pourcentages consommés, croissants (ex. 80, 90, 100) */
    public function __construct(public int $remaining, public int $granted, array $thresholds)
    {
        sort($thresholds);
        if ($remaining <= 0) {
            $this->state = self::EXHAUSTED;

            return;
        }
        $consumed = $granted > 0 ? intdiv(self::PERCENT * max(0, $granted - $remaining), $granted) : 0;
        $reached = array_values(array_filter($thresholds, static fn (int $t): bool => $t < self::PERCENT && $consumed >= $t));
        $this->state = match (\count($reached)) {
            0 => self::NORMAL,
            1 => self::ALERT,
            default => self::CRITICAL,
        };
    }
}
