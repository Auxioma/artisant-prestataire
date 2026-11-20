<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\Text\ContactDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContactDetectorTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>}> */
    public static function texts(): iterable
    {
        yield 'mobile' => ['Appelez le 06 12 34 56 78', [ContactDetector::PHONE]];
        yield 'points' => ['tel 06.12.34.56.78', [ContactDetector::PHONE]];
        yield 'international' => ['+33 6 12 34 56 78', [ContactDetector::PHONE]];
        yield 'e-mail' => ['écrivez à jean.dupont@exemple.fr', [ContactDetector::EMAIL, ContactDetector::LINK]];
        yield 'e-mail masqué' => ['jean (at) exemple (dot) com', [ContactDetector::EMAIL]];
        yield 'site' => ['voir www.mon-site.fr', [ContactDetector::LINK]];
        yield 'domaine' => ['plomberie-dupont.com', [ContactDetector::LINK]];
        yield 'année et prix' => ['Depuis 2009, à partir de 450 € pour 120 m²', []];
        yield 'horaires' => ['De 08:00 à 18:00 du lundi au vendredi', []];
        yield 'SIRET non concerné' => ['Intervention en 48 h, garantie 10 ans', []];
    }

    /** @param list<string> $expected */
    #[DataProvider('texts')]
    public function testDetection(string $text, array $expected): void
    {
        self::assertSame($expected, ContactDetector::find($text));
    }
}
