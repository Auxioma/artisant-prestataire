<?php

declare(strict_types=1);

namespace App\Identity\Application\Registration;

use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserRepository;
use App\Identity\Domain\User\UserStatus;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Application\Identity\UserPhone;

final readonly class UserPhoneHandler implements QueryHandler
{
    public function __construct(private UserRepository $users)
    {
    }

    public function __invoke(UserPhone $query): ?string
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $query->userId)) {
            return null;
        }
        $user = $this->users->find(new UserId($query->userId));

        return null !== $user && UserStatus::Active === $user->status() ? $user->phone()?->value : null;
    }
}
