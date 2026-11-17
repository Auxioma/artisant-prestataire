<?php

declare(strict_types=1);

namespace App\Administration\Infrastructure\Security;

use App\Administration\Application\Port\AdminPasswordHasher;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/** Hacheur configuré pour AdminSecurityUser (argon2id explicite, security.yaml) : identique à celui de la connexion. */
final readonly class SymfonyAdminPasswordHasher implements AdminPasswordHasher
{
    private PasswordHasherInterface $hasher;

    public function __construct(PasswordHasherFactoryInterface $factory)
    {
        $this->hasher = $factory->getPasswordHasher(AdminSecurityUser::class);
    }

    public function hash(#[\SensitiveParameter] string $password): string
    {
        return $this->hasher->hash($password);
    }
}
