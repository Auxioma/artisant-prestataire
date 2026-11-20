<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

final class InvalidValue extends DomainException
{
    public static function for(string $valueObject, string $reason): self
    {
        return new self(\sprintf('%s invalide : %s', $valueObject, $reason));
    }
}
