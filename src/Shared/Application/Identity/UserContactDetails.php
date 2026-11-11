<?php

declare(strict_types=1);

namespace App\Shared\Application\Identity;

final readonly class UserContactDetails
{
    public function __construct(public string $email, public string $firstName)
    {
    }
}
