<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidValue;
use App\Shared\Domain\ValueObject\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testArithmetic(): void
    {
        $a = new Money(12_550);
        $b = new Money(2_450);

        self::assertTrue($a->add($b)->equals(new Money(15_000)));
        self::assertTrue($a->subtract($b)->equals(new Money(10_100)));
        self::assertTrue($b->multiply(3)->equals(new Money(7_350)));
        self::assertTrue($b->subtract($a)->isNegative());
        self::assertTrue(Money::zero()->equals(new Money(0, 'EUR')));
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function rates(): iterable
    {
        yield 'TVA 20 %' => [10_000, 2_000, 2_000];
        yield 'TVA 10 % arrondi supérieur' => [1_005, 1_000, 101];
        yield 'TVA 5,5 % arrondi inférieur' => [999, 550, 55];
        yield 'demi-centime arrondi au-dessus' => [5, 1_000, 1];
        yield 'négatif symétrique' => [-5, 1_000, -1];
    }

    #[DataProvider('rates')]
    public function testApplyRateRoundsHalfUp(int $amount, int $basisPoints, int $expected): void
    {
        self::assertSame($expected, new Money($amount)->applyRate($basisPoints)->amount);
    }

    public function testRejectsCurrencyMismatch(): void
    {
        $this->expectException(InvalidValue::class);
        new Money(1)->add(new Money(1, 'USD'));
    }

    public function testRejectsInvalidCurrency(): void
    {
        $this->expectException(InvalidValue::class);
        new Money(1, 'euro');
    }
}
