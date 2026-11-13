<?php

declare(strict_types=1);

namespace App\Tests\Unit\Billing;

use App\Billing\Domain\Credit\CreditAlertPolicy;
use PHPUnit\Framework\TestCase;

/** RG-105 — seuils franchis par un débit (pourcentages consommés de la dotation, P-83). */
final class CreditAlertPolicyTest extends TestCase
{
    private const array THRESHOLDS = [80, 90, 100];

    public function testOnlyThresholdsCrossedByThisDebit(): void
    {
        $policy = new CreditAlertPolicy();
        self::assertSame([], $policy->crossed(100, 22, 21, self::THRESHOLDS), '79 % consommés');
        self::assertSame([80], $policy->crossed(100, 21, 20, self::THRESHOLDS));
        self::assertSame([], $policy->crossed(100, 20, 19, self::THRESHOLDS), '80 % déjà signalé');
        self::assertSame([90], $policy->crossed(100, 11, 10, self::THRESHOLDS));
        self::assertSame([100], $policy->crossed(100, 1, 0, self::THRESHOLDS));
    }

    public function testSmallGrantCanCrossSeveralThresholds(): void
    {
        $policy = new CreditAlertPolicy();
        self::assertSame([80], $policy->crossed(5, 2, 1, [90, 80]), '60 % → 80 %, seuils non triés');
        self::assertSame([80, 90, 100], $policy->crossed(5, 2, 0, self::THRESHOLDS), '60 % → 100 %');
        self::assertSame([], $policy->crossed(0, 0, 0, self::THRESHOLDS), 'aucune dotation');
        self::assertSame([], $policy->crossed(100, 5, 5, self::THRESHOLDS), 'pas de débit');
    }
}
