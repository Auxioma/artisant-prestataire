<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine\Type;

use App\Identity\Domain\User\UserId;
use App\Shared\Infrastructure\Doctrine\Type\AggregateIdType;

final class UserIdType extends AggregateIdType
{
    public const string NAME = 'identity_user_id';

    protected function idClass(): string
    {
        return UserId::class;
    }
}
