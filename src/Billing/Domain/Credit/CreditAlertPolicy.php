<?php

declare(strict_types=1);

namespace App\Billing\Domain\Credit;

/**
 * RG-105 — seuils d'alerte franchis par un débit. Les seuils (`credits.alert_thresholds`, `credits.plan_alert_pct`)
 * sont des pourcentages consommés de la dotation ; avec la dotation gratuite par défaut (100 devis), « 80/90/100
 * consommés » et « 80/90/100 % » coïncident (P-83). 100 % = épuisement : E-42b pour le crédit gratuit (RG-106).
 */
final readonly class CreditAlertPolicy
{
    public const int EXHAUSTED = 100;

    /**
     * @param list<int> $thresholds
     *
     * @return list<int> seuils franchis par ce débit, croissants (un seuil déjà dépassé avant le débit n'est pas repris)
     */
    public function crossed(int $granted, int $before, int $after, array $thresholds): array
    {
        if ($granted <= 0 || $after >= $before) {
            return [];
        }
        $consumed = static fn (int $left): int => intdiv(self::EXHAUSTED * max(0, $granted - $left), $granted);
        [$from, $to] = [$consumed($before), $consumed($after)];
        $crossed = array_values(array_filter(array_unique($thresholds), static fn (int $t): bool => $t > $from && $t <= $to && $t <= self::EXHAUSTED));
        sort($crossed);

        return $crossed;
    }
}
