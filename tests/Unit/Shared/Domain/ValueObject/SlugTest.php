<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidValue;
use App\Shared\Domain\ValueObject\Slug;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** CDC §7 : URLs propres en minuscules, tirets, sans accents. */
final class SlugTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function texts(): iterable
    {
        yield 'esperluette' => ['Rénovation & Construction', 'renovation-et-construction'];
        yield 'accents et majuscules' => ['Électricité', 'electricite'];
        yield 'ligature' => ['Œuvres d’art', 'oeuvres-d-art'];
        yield 'apostrophe et espaces' => ["  Pose d'enduit  ", 'pose-d-enduit'];
        yield 'ponctuation' => ['Chauffe-eau / ballon', 'chauffe-eau-ballon'];
        yield 'ville et code postal' => ['Le Havre 76600', 'le-havre-76600'];
    }

    #[DataProvider('texts')]
    public function testFromText(string $text, string $expected): void
    {
        self::assertSame($expected, Slug::fromText($text)->value);
    }

    public function testRejectsInvalidSlug(): void
    {
        $this->expectException(InvalidValue::class);
        new Slug('Plombier--Havre');
    }

    public function testRejectsTextWithoutUsableCharacter(): void
    {
        $this->expectException(InvalidValue::class);
        Slug::fromText(' — ');
    }

    public function testTruncatesLongTextOnABoundary(): void
    {
        $slug = Slug::fromText(str_repeat('ab ', 60));

        self::assertLessThanOrEqual(100, \strlen($slug->value));
        self::assertStringEndsNotWith('-', $slug->value);
    }
}
