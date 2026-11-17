<?php

declare(strict_types=1);

namespace App\Identity\Domain\Client;

use App\Identity\Domain\User\UserId;

interface ClientProfileRepository
{
    public function find(UserId $userId): ?ClientProfile;

    public function add(ClientProfile $profile): void;
}
