<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Type;

use App\Shared\Domain\ValueObject\Slug;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\StringType;

final class SlugType extends StringType
{
    public const string NAME = 'slug';

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Slug
    {
        if (null === $value || $value instanceof Slug) {
            return $value;
        }
        if (!\is_string($value)) {
            throw ValueNotConvertible::new($value, Slug::class);
        }
        try {
            return new Slug($value);
        } catch (\DomainException $e) {
            throw ValueNotConvertible::new($value, Slug::class, null, $e);
        }
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return match (true) {
            null === $value => null,
            $value instanceof Slug => $value->value,
            \is_string($value) => $value,
            default => throw InvalidType::new($value, self::NAME, ['null', 'string', Slug::class]),
        };
    }
}
