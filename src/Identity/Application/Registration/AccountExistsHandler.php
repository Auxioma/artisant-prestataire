<?php

declare(strict_types=1);

namespace App\Identity\Application\Registration;

use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserRepository;
use App\Shared\Application\Bus\QueryHandler;

final readonly class AccountExistsHandler implements QueryHandler
{
    public function __construct(private UserRepository $users)
    {
    }

    public function __invoke(AccountExists $query): bool
    {
        return null !== $this->users->find(new UserId($query->userId));
    }
}
