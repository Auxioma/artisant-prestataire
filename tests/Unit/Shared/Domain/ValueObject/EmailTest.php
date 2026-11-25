<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidValue;
use App\Shared\Domain\ValueObject\Email;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailTest extends TestCase
{
    public function testTrimsAndLowercasesDomainOnly(): void
    {
        $email = new Email('  Jean.Dupont@Exemple.FR ');

        self::assertSame('Jean.Dupont@exemple.fr', $email->value);
        self::assertSame('jean.dupont@exemple.fr', $email->canonical());
        self::assertSame('exemple.fr', $email->domain());
    }

    public function testEqualityIsCaseInsensitive(): void
    {
        self::assertTrue(new Email('A@b.fr')->equals(new Email('a@B.FR')));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidEmails(): iterable
    {
        yield 'vide' => [''];
        yield 'sans arobase' => ['jean.exemple.fr'];
        yield 'sans domaine' => ['jean@'];
        yield 'trop long' => [str_repeat('a', 250).'@ex.fr'];
    }

    #[DataProvider('invalidEmails')]
    public function testRejectsInvalidEmail(string $value): void
    {
        $this->expectException(InvalidValue::class);
        new Email($value);
    }
}
