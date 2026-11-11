<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Domain\User\UserKind;
use App\Shared\Application\Security\CurrentUser;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class SecurityCurrentUser implements CurrentUser
{
    public function __construct(private Security $security)
    {
    }

    public function id(): ?string
    {
        return $this->user()?->id;
    }

    public function isArtisan(): bool
    {
        return UserKind::Artisan === $this->user()?->kind;
    }

    public function isEmailVerified(): bool
    {
        return true === $this->user()?->emailVerified;
    }

    private function user(): ?SecurityUser
    {
        $user = $this->security->getUser();

        return $user instanceof SecurityUser ? $user : null;
    }
}
