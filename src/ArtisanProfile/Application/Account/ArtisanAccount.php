<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Account;

final readonly class ArtisanAccount
{
    public function __construct(public string $userId, public string $name)
    {
    }
}
