<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Type;

use App\Shared\Domain\Identifier\AggregateId;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\GuidType;

/**
 * Type Doctrine générique pour les identifiants d'agrégat (colonne uuid PostgreSQL).
 * Chaque identifiant concret déclare une sous-classe fournissant son nom et sa classe.
 */
abstract class AggregateIdType extends GuidType
{
    /** @return class-string<AggregateId> */
    abstract protected function idClass(): string;

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?AggregateId
    {
        if (null === $value || $value instanceof AggregateId) {
            return $value;
        }
        if (!\is_string($value)) {
            throw ValueNotConvertible::new($value, $this->idClass());
        }
        $class = $this->idClass();
        try {
            return new $class($value);
        } catch (\DomainException $e) {
            throw ValueNotConvertible::new($value, $class, null, $e);
        }
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }
        if ($value instanceof AggregateId) {
            return $value->value;
        }
        if (\is_string($value)) {
            return $value;
        }
        throw InvalidType::new($value, static::class, ['null', 'string', AggregateId::class]);
    }
}
