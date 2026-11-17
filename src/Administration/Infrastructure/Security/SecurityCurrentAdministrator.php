<?php

declare(strict_types=1);

namespace App\Administration\Infrastructure\Security;

use App\Shared\Application\Security\CurrentAdministrator;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class SecurityCurrentAdministrator implements CurrentAdministrator
{
    public function __construct(private Security $security)
    {
    }

    public function id(): ?string
    {
        $user = $this->security->getUser();

        return $user instanceof AdminSecurityUser ? $user->id : null;
    }
}
