<?php

declare(strict_types=1);

namespace App\Tests\Unit\Billing;

use App\Billing\Domain\Credit\CreditGauge;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** RG-105 : état de la jauge selon la part consommée du crédit gratuit (seuils administrables). */
final class CreditGaugeTest extends TestCase
{
    /** @return iterable<string, array{int, int, string}> */
    public static function states(): iterable
    {
        yield 'neuf' => [100, 100, CreditGauge::NORMAL];
        yield '79 % consommés' => [21, 100, CreditGauge::NORMAL];
        yield '80 % consommés' => [20, 100, CreditGauge::ALERT];
        yield '90 % consommés' => [10, 100, CreditGauge::CRITICAL];
        yield 'épuisé' => [0, 100, CreditGauge::EXHAUSTED];
        yield 'aucun crédit gratuit attribué' => [5, 0, CreditGauge::NORMAL];
    }

    #[DataProvider('states')]
    public function testState(int $remaining, int $granted, string $state): void
    {
        self::assertSame($state, new CreditGauge($remaining, $granted, [100, 80, 90])->state);
    }
}
