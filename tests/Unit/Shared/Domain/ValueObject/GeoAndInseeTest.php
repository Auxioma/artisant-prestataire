<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidValue;
use App\Shared\Domain\ValueObject\GeoPoint;
use App\Shared\Domain\ValueObject\InseeCode;
use PHPUnit\Framework\TestCase;

final class GeoAndInseeTest extends TestCase
{
    public function testInseeCodeDepartment(): void
    {
        self::assertSame('76', new InseeCode('76351')->departmentCode()); // Le Havre
        self::assertSame('2A', new InseeCode('2a004')->departmentCode()); // Ajaccio
        self::assertSame('974', new InseeCode('97411')->departmentCode()); // Saint-Denis (La Réunion)
    }

    public function testInseeCodeRejectsPostalLikeGarbage(): void
    {
        $this->expectException(InvalidValue::class);
        new InseeCode('7660');
    }

    public function testDistanceLeHavreRouenAsTheCrowFlies(): void
    {
        $leHavre = new GeoPoint(49.4944, 0.1079);
        $rouen = new GeoPoint(49.4432, 1.0999);

        self::assertEqualsWithDelta(72.0, $leHavre->distanceKmTo($rouen), 3.0);
        self::assertEqualsWithDelta(0.0, $rouen->distanceKmTo($rouen), 0.0001);
    }

    public function testGeoPointBounds(): void
    {
        $this->expectException(InvalidValue::class);
        new GeoPoint(91.0, 0.0);
    }
}
