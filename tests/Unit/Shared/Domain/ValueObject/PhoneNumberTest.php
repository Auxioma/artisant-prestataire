<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidValue;
use App\Shared\Domain\ValueObject\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhoneNumberTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function equivalentFrenchNumbers(): iterable
    {
        yield 'national espacé' => ['06 12 34 56 78'];
        yield 'national à points' => ['06.12.34.56.78'];
        yield 'international' => ['+33 6 12 34 56 78'];
        yield 'préfixe 00' => ['0033612345678'];
    }

    #[DataProvider('equivalentFrenchNumbers')]
    public function testNormalizesToE164(string $input): void
    {
        self::assertSame('+33612345678', new PhoneNumber($input)->value);
    }

    public function testMasked(): void
    {
        self::assertSame('+336******78', new PhoneNumber('0612345678')->masked());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNumbers(): iterable
    {
        yield 'trop court' => ['06123'];
        yield 'français trop long' => ['+336123456789'];
        yield 'lettres' => ['06 12 AB 56 78'];
    }

    #[DataProvider('invalidNumbers')]
    public function testRejectsInvalid(string $input): void
    {
        $this->expectException(InvalidValue::class);
        new PhoneNumber($input);
    }
}
