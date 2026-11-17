<?php

declare(strict_types=1);

namespace App\Administration\Application\Port;

interface AdminPasswordHasher
{
    public function hash(#[\SensitiveParameter] string $password): string;
}
