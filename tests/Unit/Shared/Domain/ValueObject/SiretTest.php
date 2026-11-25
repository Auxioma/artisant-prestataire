<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidValue;
use App\Shared\Domain\ValueObject\Siret;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** RG-041, A03.2 : SIRET valide (14 chiffres, clé de Luhn). */
final class SiretTest extends TestCase
{
    public function testNormalizesSeparatorsAndExposesSiren(): void
    {
        $siret = new Siret('732 829 320 00074');

        self::assertSame('73282932000074', $siret->value);
        self::assertSame('732829320', $siret->siren());
    }

    public function testAcceptsLaPosteEstablishmentWithSumOfDigitsRule(): void
    {
        self::assertSame('35600000049837', new Siret('35600000049837')->value);
        self::assertSame('35600000000048', new Siret('35600000000048')->value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSirets(): iterable
    {
        yield 'trop court' => ['7328293200007'];
        yield 'lettres' => ['7328293200007A'];
        yield 'clé de Luhn fausse' => ['73282932000075'];
        yield 'vide' => [''];
    }

    #[DataProvider('invalidSirets')]
    public function testRejectsInvalidSiret(string $value): void
    {
        $this->expectException(InvalidValue::class);
        new Siret($value);
    }

    public function testEquality(): void
    {
        self::assertTrue(new Siret('55212009500096')->equals(new Siret('552 120 095 00096')));
        self::assertFalse(new Siret('55212009500096')->equals(new Siret('44349819600015')));
    }
}
