<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Identifier;

use App\Shared\Domain\Identifier\IdGenerator;
use Symfony\Component\Uid\Uuid;

final readonly class UuidV7Generator implements IdGenerator
{
    public function next(): string
    {
        return Uuid::v7()->toRfc4122();
    }
}
