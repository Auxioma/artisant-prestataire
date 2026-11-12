<?php

declare(strict_types=1);

namespace App\Administration\Infrastructure\Security;

use App\Administration\Application\Port\CurrentAdmin;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class SecurityCurrentAdmin implements CurrentAdmin
{
    public function __construct(private Security $security)
    {
    }

    public function id(): ?string
    {
        return $this->user()?->id;
    }

    public function name(): ?string
    {
        return $this->user()?->name;
    }

    public function roleLabel(): ?string
    {
        return $this->user()?->role->label();
    }

    private function user(): ?AdminSecurityUser
    {
        $user = $this->security->getUser();

        return $user instanceof AdminSecurityUser ? $user : null;
    }
}
