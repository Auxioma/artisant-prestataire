<?php

declare(strict_types=1);

namespace App\Identity\Application\Registration;

use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserRepository;
use App\Identity\Domain\User\UserStatus;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Application\Identity\UserContact;
use App\Shared\Application\Identity\UserContactDetails;

final readonly class UserContactHandler implements QueryHandler
{
    public function __construct(private UserRepository $users)
    {
    }

    public function __invoke(UserContact $query): ?UserContactDetails
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $query->userId)) {
            return null;
        }
        $user = $this->users->find(new UserId($query->userId));
        if (null === $user || UserStatus::Anonymized === $user->status()) {
            return null;
        }

        return new UserContactDetails($user->email()->value, $user->firstName());
    }
}
