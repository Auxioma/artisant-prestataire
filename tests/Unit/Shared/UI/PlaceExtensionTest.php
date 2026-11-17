<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\UI;

use App\Shared\UI\Twig\PlaceExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlaceExtensionTest extends TestCase
{
    /** @return iterable<array{string, string}> */
    public static function places(): iterable
    {
        yield ['Harfleur', 'à Harfleur'];
        yield ['Le Havre', 'au Havre'];
        yield ['Les Andelys', 'aux Andelys'];
        yield ['La Rochelle', 'à La Rochelle'];
        yield ['Lessay', 'à Lessay'];
    }

    #[DataProvider('places')]
    public function testContractsTheArticle(string $place, string $expected): void
    {
        self::assertSame($expected, PlaceExtension::at($place));
    }
}
