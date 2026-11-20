<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Identifier;

use App\Shared\Domain\Exception\InvalidValue;
use App\Shared\Infrastructure\Identifier\UuidV7Generator;
use App\Tests\Support\TestId;
use PHPUnit\Framework\TestCase;

final class AggregateIdTest extends TestCase
{
    public function testAcceptsGeneratedUuidV7AndIsTimeOrdered(): void
    {
        $generator = new UuidV7Generator();
        $first = new TestId($generator->next());
        $second = new TestId($generator->next());

        self::assertSame('7', $first->value[14]);
        self::assertLessThan(0, strcmp($first->value, $second->value));
        self::assertFalse($first->equals($second));
        self::assertTrue($first->equals(new TestId(' '.strtoupper($first->value))));
    }

    public function testRejectsSequentialIdentifier(): void
    {
        $this->expectException(InvalidValue::class);
        new TestId('42');
    }
}
