<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\Port\LoginProtection;

final readonly class SecurityLoginProtection implements LoginProtection
{
    public function __construct(private LoginAttemptGuard $guard, private TargetPathPolicy $targets)
    {
    }

    public function captchaRequired(?string $email, ?string $ip): bool
    {
        return $this->guard->requiresCaptcha($email, $ip);
    }

    public function safeTargetPath(?string $requested): ?string
    {
        return $this->targets->sanitize($requested);
    }
}
